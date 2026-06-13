<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            // active | canceled | refunded | expired
            $table->string('status')->default('active');

            // The paid term. starts_at + plan.term_days == ends_at.
            $table->date('starts_at');
            $table->date('ends_at');

            // Set when the student leaves mid-term. Used as the "effective date"
            // that freezes instructor revenue vesting and bounds the refund.
            $table->date('canceled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
