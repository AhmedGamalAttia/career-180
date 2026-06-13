<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Student;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => Carbon::today(),
            // Overwritten in configure() to match the plan's term length.
            'ends_at' => Carbon::today()->addDays(30),
            'canceled_at' => null,
        ];
    }

    /**
     * Keep ends_at consistent with the (possibly state-overridden) plan term and
     * start date, so subscriptions created in tests always have a coherent term.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $subscription) {
            $plan = $subscription->plan;
            if ($plan !== null) {
                $start = Carbon::parse($subscription->starts_at);
                $subscription->ends_at = $start->copy()->addDays($plan->term_days);
            }
        });
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => ['plan_id' => $plan->id]);
    }

    public function startingOn(Carbon|string $date): static
    {
        return $this->state(fn () => ['starts_at' => Carbon::parse($date)]);
    }
}
