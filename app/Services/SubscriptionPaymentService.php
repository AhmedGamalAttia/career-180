<?php

namespace App\Services;

use App\Jobs\AllocateRevenueJob;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The money-IN entry point: record a subscription payment and kick off its
 * revenue allocation.
 */
class SubscriptionPaymentService
{
    /**
     * Record a payment for a subscription's term, up front.
     *
     * Idempotent on $idempotencyKey: a duplicate webhook or retried capture with
     * the same key returns the existing payment instead of recording a second one
     * (which would otherwise double the instructors' earnings).
     *
     * @param  bool  $allocateInline  run allocation now instead of queuing it
     *                                 (used by the demo seeder and tests).
     */
    public function capture(
        Subscription $subscription,
        int $amountMinor,
        string $idempotencyKey,
        ?Carbon $paidAt = null,
        bool $allocateInline = false,
    ): SubscriptionPayment {
        [$payment, $isNew] = DB::transaction(function () use ($subscription, $amountMinor, $idempotencyKey, $paidAt) {
            $existing = SubscriptionPayment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $payment = SubscriptionPayment::create([
                'subscription_id' => $subscription->id,
                'amount_minor' => $amountMinor,
                'currency' => $subscription->plan->currency,
                'period_start' => $subscription->starts_at,
                'period_end' => $subscription->ends_at,
                'paid_at' => $paidAt ?? Carbon::now(),
                'status' => SubscriptionPayment::STATUS_CAPTURED,
                'refunded_minor' => 0,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [$payment, true];
        });

        // Only the first capture triggers allocation; allocation is itself
        // idempotent, so a duplicate dispatch is harmless either way.
        if ($isNew) {
            $allocateInline
                ? app(RevenueAllocationService::class)->allocate($payment)
                : AllocateRevenueJob::dispatch($payment->id);
        }

        return $payment;
    }
}
