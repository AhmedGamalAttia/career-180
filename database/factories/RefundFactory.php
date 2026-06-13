<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        $payment = SubscriptionPayment::factory()->create();

        return [
            'subscription_payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
            'amount_minor' => 10000,
            'currency' => 'EGP',
            'effective_date' => Carbon::today(),
            'reason' => 'Student left mid-term',
            'refunded_at' => Carbon::now(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function forPayment(SubscriptionPayment $payment): static
    {
        return $this->state(fn () => [
            'subscription_payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id,
        ]);
    }
}
