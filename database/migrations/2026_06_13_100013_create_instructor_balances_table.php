<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denormalised projection of each instructor's money position. NOT the
        // source of truth — it is rebuildable at any time from revenue_allocations
        // (vested) and payouts (paid). It exists so the Filament screen and the
        // payout planner can read balances in O(1) instead of scanning tens of
        // millions of allocation rows.
        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->foreignId('instructor_id')->primary()->constrained()->cascadeOnDelete();

            // Lifetime earned (vested) to date, in minor units.
            $table->unsignedBigInteger('lifetime_vested_minor')->default(0);

            // Successfully paid out to date.
            $table->unsignedBigInteger('lifetime_paid_minor')->default(0);

            // Currently committed in pending/processing/unknown payouts.
            $table->unsignedBigInteger('in_flight_minor')->default(0);

            // available = lifetime_vested - lifetime_paid - in_flight
            // Stored explicitly for fast querying/sorting in the UI.
            $table->bigInteger('available_minor')->default(0);

            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_balances');
    }
};
