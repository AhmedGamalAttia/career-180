<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The result of dividing ONE subscription payment among the instructors
        // it covers. One row == one instructor's claim on one payment.
        //
        // Revenue is recognised on an ACCRUAL basis: `share_minor` is the full
        // amount the instructor would earn if the whole term is served; the
        // portion actually EARNED at any instant is share_minor prorated by the
        // days elapsed between term_start and term_end (frozen at
        // vesting_stopped_at when the student leaves early). Payouts only ever
        // pay the vested-and-unpaid portion, so a mid-term refund never requires
        // clawing back money already sent to an instructor.
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained();
            $table->foreignId('instructor_id')->constrained();

            // Total instructor pool for this payment (amount_minor minus platform
            // fee) — stored for auditability of the split.
            $table->unsignedBigInteger('pool_minor');

            // This instructor's full-term share of the pool.
            $table->unsignedBigInteger('share_minor');

            // The vesting window (copied from the payment period).
            $table->date('term_start');
            $table->date('term_end');

            // When set, vesting is frozen at this date (student left mid-term).
            // Earned = share_minor * (vesting_stopped_at - term_start) / term_len.
            $table->date('vesting_stopped_at')->nullable();

            // vesting | completed | canceled
            $table->string('status')->default('vesting');

            $table->timestamps();

            // IDEMPOTENCY: a payment can be allocated to a given instructor exactly
            // once. Re-running allocation (retry, double trigger) is a no-op.
            $table->unique(['subscription_payment_id', 'instructor_id']);

            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
    }
};
