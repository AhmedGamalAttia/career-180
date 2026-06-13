<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money IN. Append-only record of an actual subscription payment captured
        // up-front for a whole term. One row == one inbound payment event.
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained();

            // Amount the student actually paid for the term, in minor units.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // The term this payment covers (copied from the subscription at capture
            // time so vesting is stable even if the subscription is later edited).
            $table->date('period_start');
            $table->date('period_end');

            $table->timestamp('paid_at');

            // captured | refunded | partially_refunded
            $table->string('status')->default('captured');

            // Total refunded against this payment so far, in minor units.
            $table->unsignedBigInteger('refunded_minor')->default(0);

            // Reference returned by the inbound payment gateway.
            $table->string('external_payment_ref')->nullable();

            // Inbound idempotency: a duplicate webhook / retried capture carrying
            // the same key must never create a second payment (and thus never a
            // second revenue allocation).
            $table->string('idempotency_key')->unique();

            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
