<?php

namespace App\Jobs;

use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a single instructor payout to the provider.
 *
 * Safe to run more than once. If the queue retries this job after a crash, or two
 * copies are dispatched, PayoutService::processPayout is idempotent (row lock +
 * settled-check) and the provider de-duplicates on the idempotency key, so the
 * instructor is paid at most once.
 */
class ProcessPayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly int $payoutId)
    {
    }

    /**
     * Stop two copies of this job from processing the same payout at the same
     * time. Defence in depth alongside the DB lock and provider idempotency.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->payoutId))->expireAfter(180)];
    }

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::find($this->payoutId);

        if ($payout === null || $payout->isSettled()) {
            return;
        }

        $payouts->processPayout($payout);
    }
}
