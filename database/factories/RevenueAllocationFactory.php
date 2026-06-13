<?php

namespace Database\Factories;

use App\Models\Instructor;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RevenueAllocation>
 */
class RevenueAllocationFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::today();

        return [
            'subscription_payment_id' => SubscriptionPayment::factory(),
            'instructor_id' => Instructor::factory(),
            'pool_minor' => 21000,
            'share_minor' => 21000,
            'term_start' => $start,
            'term_end' => $start->copy()->addDays(30),
            'vesting_stopped_at' => null,
            'status' => RevenueAllocation::STATUS_VESTING,
        ];
    }

    public function fullyVested(): static
    {
        return $this->state(fn () => [
            'term_start' => Carbon::today()->subDays(60),
            'term_end' => Carbon::today()->subDays(30),
            'status' => RevenueAllocation::STATUS_COMPLETED,
        ]);
    }
}
