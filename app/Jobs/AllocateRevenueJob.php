<?php

namespace App\Jobs;

use App\Models\SubscriptionPayment;
use App\Services\RevenueAllocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Splits a captured payment among its instructors. Safe to retry: allocation is
 * idempotent (row lock + unique(payment, instructor)), so a job that crashes and
 * is retried never creates a second set of allocations.
 */
class AllocateRevenueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public readonly int $subscriptionPaymentId)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->subscriptionPaymentId))->expireAfter(180)];
    }

    public function handle(RevenueAllocationService $allocator): void
    {
        $payment = SubscriptionPayment::find($this->subscriptionPaymentId);

        if ($payment !== null) {
            $allocator->allocate($payment);
        }
    }
}
