<?php

namespace App\Services;

use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns ONE captured subscription payment into per-instructor revenue claims.
 *
 * Guarantees:
 *  - Idempotent. Running it twice for the same payment (retry, double trigger,
 *    two servers) produces exactly one set of allocations. A row lock on the
 *    payment serialises concurrent callers; a unique(payment, instructor) index
 *    is the database-level backstop.
 *  - Conserves money. The instructor pool is split to the piaster with zero
 *    loss: sum(share_minor) == pool_minor, always.
 *  - Deterministic split. Instructors are ordered by id and any rounding
 *    remainder is handed out one piaster at a time from the lowest id up, so the
 *    division is reproducible and auditable.
 */
class RevenueAllocationService
{
    private const BPS_DENOMINATOR = 10000;

    /**
     * Allocate the given payment to its instructors.
     *
     * @return int Number of allocation rows created (0 if already allocated or
     *             there are no participating instructors).
     */
    public function allocate(SubscriptionPayment $payment): int
    {
        return DB::transaction(function () use ($payment) {
            // Serialise concurrent allocation of the SAME payment. The second
            // caller blocks here until the first commits, then sees the rows
            // already exist and no-ops below.
            $payment = SubscriptionPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent short-circuit: the instructor set is snapshotted on the
            // first run and never recomputed, so later enrolment changes can't
            // silently inflate the split beyond the pool.
            if ($payment->revenueAllocations()->exists()) {
                return 0;
            }

            $subscription = $payment->subscription()->with('plan')->firstOrFail();
            $plan = $subscription->plan;

            $instructorIds = $subscription->participatingInstructorIds();
            $count = count($instructorIds);

            // No instructors on this subscription: the platform keeps the whole
            // amount; there is nothing to allocate.
            if ($count === 0) {
                return 0;
            }

            $pool = $this->instructorPool($payment->amount_minor, $plan->platform_fee_bps);
            $shares = $this->splitEvenly($pool, $count);

            $now = Carbon::now();
            $rows = [];
            foreach ($instructorIds as $i => $instructorId) {
                $rows[] = [
                    'subscription_payment_id' => $payment->id,
                    'instructor_id' => $instructorId,
                    'pool_minor' => $pool,
                    'share_minor' => $shares[$i],
                    'term_start' => $payment->period_start->toDateString(),
                    'term_end' => $payment->period_end->toDateString(),
                    'vesting_stopped_at' => null,
                    'status' => RevenueAllocation::STATUS_VESTING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // insertOrIgnore: even if a racing transaction slipped past the lock
            // (e.g. different connection semantics), the unique index makes the
            // duplicate insert a silent no-op rather than a double allocation.
            RevenueAllocation::query()->insertOrIgnore($rows);

            return count($rows);
        });
    }

    /**
     * The instructor revenue pool for a payment: the amount minus the platform's
     * cut. Floor division on the fee means any sub-piaster rounding favours the
     * instructors (the platform absorbs the fraction), and pool + fee == amount.
     */
    public function instructorPool(int $amountMinor, int $platformFeeBps): int
    {
        $platformFee = intdiv($amountMinor * $platformFeeBps, self::BPS_DENOMINATOR);

        return $amountMinor - $platformFee;
    }

    /**
     * Split an integer pool into $count parts that sum exactly to the pool.
     * The first ($pool % $count) parts get one extra piaster.
     *
     * @return list<int>
     */
    public function splitEvenly(int $pool, int $count): array
    {
        $base = intdiv($pool, $count);
        $remainder = $pool - ($base * $count);

        $shares = [];
        for ($i = 0; $i < $count; $i++) {
            $shares[] = $base + ($i < $remainder ? 1 : 0);
        }

        return $shares;
    }
}
