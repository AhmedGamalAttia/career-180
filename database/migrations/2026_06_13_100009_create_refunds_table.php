<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A refund issued against a subscription payment, possibly mid-term.
        // Processing a refund freezes instructor vesting at `effective_date` and
        // returns the unconsumed portion to the student.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained();
            $table->foreignId('subscription_id')->constrained();

            // Amount returned to the student, in minor units.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EGP');

            // The date the student is treated as having left. Vesting is frozen
            // here; everything earned up to this date stays earned.
            $table->date('effective_date');

            $table->string('reason')->nullable();
            $table->timestamp('refunded_at');

            // Idempotency for refund processing (retried jobs / duplicate events).
            $table->string('idempotency_key')->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
