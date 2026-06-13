<?php

namespace Database\Factories;

use App\Models\Instructor;
use App\Models\Payout;
use App\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payout>
 */
class PayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payout_batch_id' => PayoutBatch::factory(),
            'instructor_id' => Instructor::factory(),
            'amount_minor' => 21000,
            'currency' => 'EGP',
            'status' => Payout::STATUS_PENDING,
            'attempts' => 0,
            'provider_idempotency_key' => (string) Str::uuid(),
            'external_payout_ref' => null,
            'last_checked_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => Payout::STATUS_PAID,
            'external_payout_ref' => 'po_'.fake()->bothify('??########'),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => ['status' => Payout::STATUS_UNKNOWN]);
    }
}
