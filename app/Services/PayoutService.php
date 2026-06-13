<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutBatch;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\ProviderResult;
use App\Services\Payments\ProviderStatus;
use App\Services\Payments\ProviderTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayoutService
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly BalanceService $balances,
    ) {
    }

    /**
     * Open (or return the existing) payout batch for a period. The unique
     * period_key means concurrent runs, a manual re-trigger, or two servers all
     * converge on a single batch row instead of creating duplicates.
     */
    public function openBatch(string $periodKey, ?Carbon $scheduledFor = null): PayoutBatch
    {
        return PayoutBatch::firstOrCreate(
            ['period_key' => $periodKey],
            [
                'scheduled_for' => $scheduledFor ?? Carbon::today(),
                'status' => PayoutBatch::STATUS_PENDING,
            ],
        );
    }

    /**
     * Create one payout row per instructor who is owed money, snapshotting the
     * amount (available balance) at planning time.
     *
     * Idempotent: unique(batch, instructor) + insertOrIgnore means re-planning the
     * same batch never creates a second payout for an instructor.
     *
     * @return int number of payout rows created
     */
    public function planPayouts(PayoutBatch $batch, ?Carbon $asOf = null): int
    {
        $asOf ??= Carbon::now();
        $created = 0;

        Instructor::query()->chunkById(500, function ($instructors) use ($batch, $asOf, &$created) {
            $now = Carbon::now();
            $rows = [];

            foreach ($instructors as $instructor) {
                $amount = $this->balances->availableFor($instructor, $asOf);
                if ($amount <= 0) {
                    continue;
                }

                $rows[] = [
                    'payout_batch_id' => $batch->id,
                    'instructor_id' => $instructor->id,
                    'amount_minor' => $amount,
                    'currency' => 'EGP',
                    'status' => Payout::STATUS_PENDING,
                    'attempts' => 0,
                    // Deterministic per (batch, instructor): the SAME key is reused
                    // on every retry so the provider can de-duplicate.
                    'provider_idempotency_key' => $this->idempotencyKey($batch, $instructor),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $created += Payout::query()->insertOrIgnore($rows);
            }
        });

        return $created;
    }

    /**
     * Send a single payout to the provider, safely.
     *
     * Double-pay protection, in layers:
     *  1. A row lock + settled-check claims the payout; an already paid/failed
     *     payout is never touched again.
     *  2. The provider call happens OUTSIDE the lock (no network I/O under a lock)
     *     but uses a stable idempotency key, so even two concurrent sends move
     *     money at most once.
     *  3. A timeout never becomes "paid" — it becomes `unknown` for reconciliation.
     */
    public function processPayout(Payout $payout): void
    {
        // Phase 1 — claim. Returns null if already settled (idempotent no-op).
        $claimed = DB::transaction(function () use ($payout) {
            $fresh = Payout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isSettled()) {
                return null;
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->attempts++;
            $fresh->save();

            return $fresh;
        });

        if ($claimed === null) {
            return;
        }

        // Phase 2 — call the provider without holding any lock.
        try {
            $result = $this->provider->pay(
                $claimed->provider_idempotency_key,
                $claimed->amount_minor,
                $claimed->currency,
                $claimed->instructor->payout_account_ref ?? '',
            );

            $this->settle($claimed, $result, PayoutAttempt::KIND_SEND);
        } catch (ProviderTimeoutException $e) {
            // Outcome unknown — record it and leave the payout for reconciliation.
            $this->markUnknown($claimed, PayoutAttempt::KIND_SEND, PayoutAttempt::RESULT_TIMEOUT);
        }
    }

    /**
     * Resolve a payout stuck in `unknown` by asking the provider what really
     * happened, using the same idempotency key. Never sends a new payment.
     */
    public function reconcilePayout(Payout $payout): void
    {
        if ($payout->status !== Payout::STATUS_UNKNOWN) {
            return;
        }

        $result = $this->provider->checkStatus($payout->provider_idempotency_key);

        // NotFound means the money never moved: it is safe to mark failed so the
        // amount returns to the instructor's available balance for a later batch.
        if ($result->status === ProviderStatus::NotFound) {
            $result = ProviderResult::failed();
        }

        $this->settle($payout, $result, PayoutAttempt::KIND_STATUS_CHECK);
    }

    /**
     * Apply a definitive provider result to a payout, under a lock, idempotently.
     */
    private function settle(Payout $payout, ProviderResult $result, string $attemptKind): void
    {
        DB::transaction(function () use ($payout, $result, $attemptKind) {
            $fresh = Payout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            // Another worker may have settled it already; don't overwrite.
            if ($fresh->isSettled()) {
                return;
            }

            if ($result->status === ProviderStatus::Succeeded) {
                $fresh->status = Payout::STATUS_PAID;
                $fresh->external_payout_ref = $result->reference;
                $attemptResult = PayoutAttempt::RESULT_SUCCESS;
            } else {
                $fresh->status = Payout::STATUS_FAILED;
                $attemptResult = PayoutAttempt::RESULT_FAILED;
            }

            $fresh->last_checked_at = Carbon::now();
            $fresh->save();

            $this->recordAttempt($fresh, $attemptKind, $attemptResult, $result->reference);
        });

        // Keep the cached projection in step with the settled payout.
        $this->balances->recompute($payout->instructor()->firstOrFail());
    }

    private function markUnknown(Payout $payout, string $attemptKind, string $attemptResult): void
    {
        DB::transaction(function () use ($payout, $attemptKind, $attemptResult) {
            $fresh = Payout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isSettled()) {
                return;
            }

            $fresh->status = Payout::STATUS_UNKNOWN;
            $fresh->last_checked_at = Carbon::now();
            $fresh->save();

            $this->recordAttempt($fresh, $attemptKind, $attemptResult, null);
        });
    }

    private function recordAttempt(Payout $payout, string $kind, string $result, ?string $reference): void
    {
        PayoutAttempt::create([
            'payout_id' => $payout->id,
            'kind' => $kind,
            'result' => $result,
            'provider_reference' => $reference,
            'response_payload' => ['status' => $result, 'reference' => $reference],
            'created_at' => Carbon::now(),
        ]);
    }

    private function idempotencyKey(PayoutBatch $batch, Instructor $instructor): string
    {
        return sprintf('payout_b%d_i%d', $batch->id, $instructor->id);
    }
}
