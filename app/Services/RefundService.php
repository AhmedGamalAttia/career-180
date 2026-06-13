<?php

namespace App\Services;

use App\Models\Refund;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles a student leaving mid-term.
 *
 * Because revenue vests daily (accrual), a refund is clean: we freeze every
 * affected allocation at the leave date, so instructors keep exactly what they
 * earned for the days served and lose only the unearned remainder — which is the
 * same money returned to the student. No payout ever has to be clawed back.
 */
class RefundService
{
    public function __construct(private readonly BalanceService $balances)
    {
    }

    /**
     * Refund the unconsumed portion of a payment, effective on $effectiveDate
     * (defaults to today). Idempotent on $idempotencyKey.
     */
    public function refund(
        SubscriptionPayment $payment,
        ?Carbon $effectiveDate = null,
        ?string $idempotencyKey = null,
    ): Refund {
        $effectiveDate ??= Carbon::today();
        $idempotencyKey ??= (string) Str::uuid();

        $refund = DB::transaction(function () use ($payment, $effectiveDate, $idempotencyKey) {
            // Lock the payment so two refund attempts can't both process.
            $payment = SubscriptionPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent short-circuit.
            $existing = Refund::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $refundAmount = $this->unconsumedAmount($payment, $effectiveDate);

            // Freeze vesting on every allocation of this payment at the leave date.
            $this->freezeAllocations($payment, $effectiveDate);

            $refund = Refund::create([
                'subscription_payment_id' => $payment->id,
                'subscription_id' => $payment->subscription_id,
                'amount_minor' => $refundAmount,
                'currency' => $payment->currency,
                'effective_date' => $effectiveDate->toDateString(),
                'reason' => 'Student left mid-term',
                'refunded_at' => Carbon::now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment->refunded_minor += $refundAmount;
            $payment->status = $payment->refunded_minor >= $payment->amount_minor
                ? SubscriptionPayment::STATUS_REFUNDED
                : SubscriptionPayment::STATUS_PARTIALLY_REFUNDED;
            $payment->save();

            Subscription::whereKey($payment->subscription_id)->update([
                'status' => Subscription::STATUS_REFUNDED,
                'canceled_at' => $effectiveDate->toDateString(),
            ]);

            return $refund;
        });

        // Refresh cached balances for the instructors whose vesting was frozen.
        $this->recomputeAffectedBalances($payment);

        return $refund;
    }

    /**
     * The portion of the gross payment not yet consumed at $effectiveDate.
     * Floored, so any sub-piaster rounding stays with the platform.
     */
    public function unconsumedAmount(SubscriptionPayment $payment, Carbon $effectiveDate): int
    {
        $start = $payment->period_start->copy()->startOfDay();
        $end = $payment->period_end->copy()->startOfDay();

        $termDays = $start->diffInDays($end);
        if ($termDays <= 0) {
            return 0;
        }

        $effective = $effectiveDate->copy()->startOfDay();
        if ($effective->lessThan($start)) {
            $effective = $start;
        }
        if ($effective->greaterThan($end)) {
            $effective = $end;
        }

        $consumedDays = $start->diffInDays($effective);
        $remainingDays = $termDays - $consumedDays;

        return intdiv($payment->amount_minor * $remainingDays, $termDays);
    }

    private function freezeAllocations(SubscriptionPayment $payment, Carbon $effectiveDate): void
    {
        $allocations = RevenueAllocation::where('subscription_payment_id', $payment->id)
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            // Already frozen earlier or after the term — leave as is.
            if ($allocation->vesting_stopped_at !== null) {
                continue;
            }

            $allocation->vesting_stopped_at = $effectiveDate->toDateString();
            $allocation->status = $effectiveDate->lessThanOrEqualTo($allocation->term_start)
                ? RevenueAllocation::STATUS_CANCELED
                : $allocation->status;
            $allocation->save();
        }
    }

    private function recomputeAffectedBalances(SubscriptionPayment $payment): void
    {
        RevenueAllocation::with('instructor')
            ->where('subscription_payment_id', $payment->id)
            ->get()
            ->pluck('instructor')
            ->unique('id')
            ->each(fn ($instructor) => $this->balances->recompute($instructor));
    }
}
