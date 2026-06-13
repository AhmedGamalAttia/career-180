<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One instructor's payout within one batch. Money OUT.
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_batch_id')->constrained();
            $table->foreignId('instructor_id')->constrained();

            // Amount to pay = vested-and-unpaid balance snapshotted when the
            // payout row is created, in minor units.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // pending     - created, not yet sent
            // processing  - handed to the provider
            // paid        - provider confirmed success
            // failed      - provider confirmed permanent failure
            // unknown     - provider timed out AFTER possibly moving money;
            //               must be resolved by a status check, never re-sent blindly
            $table->string('status')->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);

            // The key sent to the external provider. Identical across retries of
            // the same payout so the provider itself de-duplicates: a job that
            // crashed after the money moved will, on retry, send the same key and
            // get back "already processed" instead of paying twice.
            $table->string('provider_idempotency_key')->unique();

            $table->string('external_payout_ref')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            // IDEMPOTENCY: at most one payout per instructor per batch.
            $table->unique(['payout_batch_id', 'instructor_id']);

            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
