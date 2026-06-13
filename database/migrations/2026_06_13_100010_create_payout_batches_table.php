<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One scheduled payout run. The unique period_key is the first line of
        // defence against double-paying: two overlapping schedules, a manual
        // re-trigger, or two servers can all try to open the "2026-06" batch,
        // but only one row can exist.
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();

            // e.g. "2026-06" (monthly run) — the logical period being paid.
            $table->string('period_key')->unique();

            $table->date('scheduled_for');

            // pending | processing | completed | failed
            $table->string('status')->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
