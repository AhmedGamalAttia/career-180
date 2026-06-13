<?php

namespace App\Jobs;

use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Resolves a payout left in the `unknown` state by a provider timeout, by asking
 * the provider what really happened. Never initiates a new payment.
 */
class ReconcilePayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly int $payoutId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->payoutId))->expireAfter(180)];
    }

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::find($this->payoutId);

        if ($payout === null || $payout->status !== Payout::STATUS_UNKNOWN) {
            return;
        }

        $payouts->reconcilePayout($payout);
    }
}
