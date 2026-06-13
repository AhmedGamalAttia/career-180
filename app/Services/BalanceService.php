<?php

namespace App\Services;

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Payout;
use App\Models\RevenueAllocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Answers the three questions the platform must always be able to answer for an
 * instructor: how much is earned, how much is paid, how much is still owed.
 *
 * The source of truth is always the append-only tables (revenue_allocations and
 * payouts). instructor_balances is only a cache that this service maintains and
 * can rebuild from scratch at any time.
 */
class BalanceService
{
    /**
     * Total EARNED (vested) to date — sum of each allocation's vested portion.
     *
     * Note: this scans the instructor's allocations in PHP because vesting is a
     * date calculation. At platform scale this is why the projection table exists
     * (and why vesting could later be materialised daily); for correctness it is
     * the authoritative figure.
     */
    public function vestedToDate(Instructor $instructor, ?CarbonInterface $asOf = null): int
    {
        $asOf ??= Carbon::now();

        return RevenueAllocation::query()
            ->where('instructor_id', $instructor->id)
            ->where('status', '!=', RevenueAllocation::STATUS_CANCELED)
            ->get()
            ->sum(fn (RevenueAllocation $allocation) => $allocation->vestedAmount($asOf));
    }

    /**
     * Money already paid out (provider-confirmed) to the instructor.
     */
    public function paidToDate(Instructor $instructor): int
    {
        return (int) Payout::query()
            ->where('instructor_id', $instructor->id)
            ->where('status', Payout::STATUS_PAID)
            ->sum('amount_minor');
    }

    /**
     * Money committed to payouts that are not yet definitively settled
     * (pending / processing / unknown). This is excluded from "available" so a
     * second payout run cannot pay it again while the first is still in flight or
     * awaiting reconciliation.
     */
    public function inFlight(Instructor $instructor): int
    {
        return (int) Payout::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', Payout::IN_FLIGHT_STATUSES)
            ->sum('amount_minor');
    }

    /**
     * What can be paid right now: earned, minus paid, minus in-flight. Never
     * negative.
     */
    public function availableFor(Instructor $instructor, ?CarbonInterface $asOf = null): int
    {
        $available = $this->vestedToDate($instructor, $asOf)
            - $this->paidToDate($instructor)
            - $this->inFlight($instructor);

        return max(0, $available);
    }

    /**
     * Recompute and persist the cached projection row for an instructor.
     */
    public function recompute(Instructor $instructor, ?CarbonInterface $asOf = null): InstructorBalance
    {
        $vested = $this->vestedToDate($instructor, $asOf);
        $paid = $this->paidToDate($instructor);
        $inFlight = $this->inFlight($instructor);

        return InstructorBalance::updateOrCreate(
            ['instructor_id' => $instructor->id],
            [
                'lifetime_vested_minor' => $vested,
                'lifetime_paid_minor' => $paid,
                'in_flight_minor' => $inFlight,
                'available_minor' => max(0, $vested - $paid - $inFlight),
            ],
        );
    }
}
