<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        // Default to a monthly plan; use the states below for other terms.
        return [
            'key' => 'monthly',
            'name' => 'Monthly',
            'price_minor' => 30000,   // EGP 300.00
            'currency' => 'EGP',
            'term_days' => 30,
            'platform_fee_bps' => 3000, // 30% platform cut
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'key' => 'monthly',
            'name' => 'Monthly',
            'price_minor' => 30000,
            'term_days' => 30,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'key' => 'quarterly',
            'name' => '3-Month',
            'price_minor' => 81000,   // EGP 810.00 (10% off)
            'term_days' => 90,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'key' => 'annual',
            'name' => 'Annual',
            'price_minor' => 288000,  // EGP 2,880.00 (20% off)
            'term_days' => 365,
        ]);
    }

    public function platformFeeBps(int $bps): static
    {
        return $this->state(fn () => ['platform_fee_bps' => $bps]);
    }
}
