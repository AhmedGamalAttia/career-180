<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit of every interaction with the external payment
        // provider for a payout: the initial send AND any later status checks.
        // This is the reconciliation trail used to resolve `unknown` payouts.
        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();

            // send | status_check
            $table->string('kind');

            // success | failed | timeout | unknown
            $table->string('result');

            // Provider reference if one was returned.
            $table->string('provider_reference')->nullable();

            // Raw provider response for debugging / audit.
            $table->json('response_payload')->nullable();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_attempts');
    }
};
