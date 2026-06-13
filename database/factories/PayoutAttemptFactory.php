<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\PayoutAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayoutAttempt>
 */
class PayoutAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payout_id' => Payout::factory(),
            'kind' => PayoutAttempt::KIND_SEND,
            'result' => PayoutAttempt::RESULT_SUCCESS,
            'provider_reference' => 'po_'.fake()->bothify('??########'),
            'response_payload' => ['status' => 'success'],
            'created_at' => Carbon::now(),
        ];
    }
}
