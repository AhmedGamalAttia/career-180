<?php

namespace Database\Factories;

use App\Models\PayoutBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayoutBatch>
 */
class PayoutBatchFactory extends Factory
{
    public function definition(): array
    {
        $month = Carbon::today();

        return [
            'period_key' => $month->format('Y-m'),
            'scheduled_for' => $month,
            'status' => PayoutBatch::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
