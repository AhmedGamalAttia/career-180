<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class RevenueAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\RevenueAllocationFactory> */
    use HasFactory;

    public const STATUS_VESTING = 'vesting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'subscription_payment_id',
        'instructor_id',
        'pool_minor',
        'share_minor',
        'term_start',
        'term_end',
        'vesting_stopped_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pool_minor' => 'integer',
            'share_minor' => 'integer',
            'term_start' => 'date',
            'term_end' => 'date',
            'vesting_stopped_at' => 'date',
        ];
    }

    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * The amount of this allocation that is EARNED (vested) as of $asOf.
     *
     * Revenue vests linearly over the term, by whole days:
     *   vested = share_minor * elapsed_days / term_days
     *
     * - Before the term starts: 0.
     * - At/after the term end (or once the whole term has elapsed): full share.
     * - If vesting was frozen (student left mid-term), nothing vests past
     *   vesting_stopped_at — the earned amount is locked at that date.
     *
     * Integer-only arithmetic (floor division) keeps money exact and is
     * intentionally conservative: an instructor never vests a fractional
     * piaster early.
     */
    public function vestedAmount(?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf ? $asOf->copy() : Carbon::now();

        $start = $this->term_start->copy()->startOfDay();
        $end = $this->term_end->copy()->startOfDay();

        // The point past which no further vesting occurs: the earlier of the
        // term end and the freeze date (if the student left early).
        $cap = $end;
        if ($this->vesting_stopped_at !== null) {
            $stop = $this->vesting_stopped_at->copy()->startOfDay();
            if ($stop->lessThan($cap)) {
                $cap = $stop;
            }
        }

        $effectiveEnd = $asOf->copy()->startOfDay();
        if ($effectiveEnd->greaterThan($cap)) {
            $effectiveEnd = $cap;
        }

        // Total vesting length in days. Guard against a zero-length term.
        $termDays = $start->diffInDays($end);
        if ($termDays <= 0) {
            // Degenerate term: treat as fully earned once started.
            return $effectiveEnd->greaterThanOrEqualTo($start) ? $this->share_minor : 0;
        }

        $elapsed = $start->diffInDays($effectiveEnd, absolute: false);
        if ($elapsed <= 0) {
            return 0;
        }
        if ($elapsed >= $termDays) {
            return $this->share_minor;
        }

        return intdiv($this->share_minor * $elapsed, $termDays);
    }
}
