<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            // External payout-provider account reference (where money is sent).
            $table->string('payout_account_ref')->nullable();

            // Optional per-instructor override of the platform fee, in basis points.
            // When null the plan's platform_fee_bps applies. Kept here for future
            // negotiated deals; not required by the core flow.
            $table->unsignedInteger('revenue_share_bps_override')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructors');
    }
};
