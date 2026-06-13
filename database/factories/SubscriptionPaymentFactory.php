<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            // Amount/period are aligned to the subscription's plan in configure().
            'amount_minor' => 30000,
            'currency' => 'EGP',
            'period_start' => Carbon::today(),
            'period_end' => Carbon::today()->addDays(30),
            'paid_at' => Carbon::now(),
            'status' => SubscriptionPayment::STATUS_CAPTURED,
            'refunded_minor' => 0,
            'external_payment_ref' => 'pay_'.fake()->unique()->bothify('??########'),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SubscriptionPayment $payment) {
            $subscription = $payment->subscription;
            if ($subscription !== null) {
                $payment->amount_minor = $subscription->plan->price_minor;
                $payment->period_start = $subscription->starts_at;
                $payment->period_end = $subscription->ends_at;
            }
        });
    }
}
