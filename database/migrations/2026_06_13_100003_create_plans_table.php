<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            // monthly | quarterly | annual
            $table->string('key')->unique();
            $table->string('name');

            // Price the student pays up-front for the whole term, in minor units
            // (piasters). Integer money only — never floats/decimals.
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('EGP');

            // Length of the paid term in days. Drives both subscription end date
            // and the daily revenue-vesting schedule.
            $table->unsignedSmallInteger('term_days');

            // Platform cut in basis points (e.g. 3000 = 30%). The remaining
            // (10000 - platform_fee_bps) is the instructor revenue pool.
            $table->unsignedInteger('platform_fee_bps');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
