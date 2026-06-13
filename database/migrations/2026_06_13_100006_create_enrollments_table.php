<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which courses (and therefore which instructors) a subscription gives
        // access to. The set of DISTINCT instructors here is the group a single
        // subscription payment is divided among.
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained();

            // Denormalised from courses.instructor_id so revenue allocation can
            // determine participating instructors without an extra join at scale.
            $table->foreignId('instructor_id')->constrained();

            $table->timestamps();

            $table->unique(['subscription_id', 'course_id']);
            $table->index(['subscription_id', 'instructor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
