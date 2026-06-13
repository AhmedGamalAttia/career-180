<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            // Lazy: the closure only runs when course_id is NOT overridden by a
            // state, so forCourse()/forInstructor() never create an orphan course.
            'course_id' => fn () => Course::factory()->create()->id,
            'instructor_id' => null,
        ];
    }

    /**
     * Keep the denormalised instructor_id in sync with whatever course this
     * enrollment ended up pointing at.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Enrollment $enrollment) {
            if ($enrollment->instructor_id === null && $enrollment->course_id !== null) {
                $enrollment->instructor_id = Course::find($enrollment->course_id)?->instructor_id;
            }
        });
    }

    public function forInstructor(Instructor $instructor): static
    {
        return $this->state(function () use ($instructor) {
            $course = Course::factory()->for($instructor)->create();

            return [
                'course_id' => $course->id,
                'instructor_id' => $instructor->id,
            ];
        });
    }

    public function forCourse(Course $course): static
    {
        return $this->state(fn () => [
            'course_id' => $course->id,
            'instructor_id' => $course->instructor_id,
        ]);
    }
}
