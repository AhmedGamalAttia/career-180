<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\PayoutService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Filament admin login.
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@career180.test',
            'password' => bcrypt('password'),
        ]);

        $plans = collect([
            Plan::factory()->monthly()->create(),
            Plan::factory()->quarterly()->create(),
            Plan::factory()->annual()->create(),
        ]);

        // Instructors, each teaching a couple of courses.
        $instructors = Instructor::factory(6)->create();
        $courses = $instructors->flatMap(
            fn (Instructor $i) => Course::factory(2)->for($i)->create()
        );

        $paymentService = app(SubscriptionPaymentService::class);
        $balanceService = app(BalanceService::class);

        // Students with subscriptions that started in the past so revenue has had
        // time to vest. Each subscription spans 1–3 instructors' courses.
        Student::factory(40)->create()->each(function (Student $student, int $idx) use ($plans, $courses, $paymentService) {
            $plan = $plans->random();
            $startedDaysAgo = fake()->numberBetween(20, 200);
            $startsAt = Carbon::today()->subDays($startedDaysAgo);

            $subscription = Subscription::factory()
                ->for($student)
                ->forPlan($plan)
                ->startingOn($startsAt)
                ->create();

            foreach ($courses->random(fake()->numberBetween(1, 3)) as $course) {
                Enrollment::factory()->forCourse($course)->for($subscription)->create();
            }

            $paymentService->capture(
                $subscription,
                $plan->price_minor,
                "seed_cap_{$subscription->id}",
                $startsAt,
                allocateInline: true,
            );
        });

        // Refresh the cached balances from the freshly created ledger.
        Instructor::all()->each(fn (Instructor $i) => $balanceService->recompute($i));

        // Run a real payout cycle (random provider) so the UI shows a mix of
        // paid / failed / unknown payouts, then reconcile the unknown ones.
        $payouts = app(PayoutService::class);
        $batch = $payouts->openBatch(Carbon::now()->format('Y-m'), Carbon::today());
        $payouts->planPayouts($batch);

        $batch->payouts()->where('status', 'pending')->get()
            ->each(fn ($payout) => $payouts->processPayout($payout));

        $batch->payouts()->where('status', 'unknown')->get()
            ->each(fn ($payout) => $payouts->reconcilePayout($payout));

        Instructor::all()->each(fn (Instructor $i) => $balanceService->recompute($i));

        $this->command->info('Seeded '.$instructors->count().' instructors and a payout batch.');
        $this->command->info('Filament login: admin@career180.test / password');
    }
}
