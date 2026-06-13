<?php

use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\RevenueAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a captured payment whose subscription is enrolled with the given
 * instructors, then return [payment, instructors].
 *
 * @param  list<Instructor>  $instructors
 */
function paymentWithInstructors(Plan $plan, array $instructors): SubscriptionPayment
{
    $subscription = Subscription::factory()->forPlan($plan)->create();

    foreach ($instructors as $instructor) {
        Enrollment::factory()
            ->forInstructor($instructor)
            ->for($subscription)
            ->create();
    }

    return SubscriptionPayment::factory()->for($subscription)->create();
}

it('splits the pool after the platform cut and conserves every piaster', function () {
    // EGP 300.00 with a 30% platform cut => pool of EGP 210.00 (21000 piasters).
    $plan = Plan::factory()->create(['price_minor' => 30000, 'platform_fee_bps' => 3000]);
    $instructors = Instructor::factory()->count(2)->create();

    $payment = paymentWithInstructors($plan, $instructors->all());

    $created = app(RevenueAllocationService::class)->allocate($payment);

    expect($created)->toBe(2);

    $allocations = RevenueAllocation::where('subscription_payment_id', $payment->id)->get();
    expect($allocations)->toHaveCount(2);
    expect($allocations->sum('share_minor'))->toBe(21000); // nothing lost
    expect($allocations->pluck('share_minor')->all())->toEqual([10500, 10500]);
    expect($allocations->every(fn ($a) => $a->pool_minor === 21000))->toBeTrue();
});

it('hands the rounding remainder to the lowest instructor ids deterministically', function () {
    // Pool of 21000 across 3 instructors => 7000 each, remainder 0... use an
    // amount that does NOT divide evenly: 21001 pool would need an odd price.
    // 30001 @ 30% fee => fee 9000 (floor), pool 21001, /3 => 7000,7000,7001? no:
    // base 7000, remainder 1 => first instructor gets the extra piaster.
    $plan = Plan::factory()->create(['price_minor' => 30001, 'platform_fee_bps' => 3000]);
    $instructors = Instructor::factory()->count(3)->create();

    $payment = paymentWithInstructors($plan, $instructors->all());

    app(RevenueAllocationService::class)->allocate($payment);

    $shares = RevenueAllocation::where('subscription_payment_id', $payment->id)
        ->orderBy('instructor_id')
        ->pluck('share_minor')
        ->all();

    // fee = floor(30001 * 0.30) = 9000; pool = 21001; 21001 = 7001 + 7000 + 7000
    expect(array_sum($shares))->toBe(21001);
    expect($shares)->toEqual([7001, 7000, 7000]);
});

it('is idempotent: allocating the same payment twice creates one set', function () {
    $plan = Plan::factory()->create(['price_minor' => 30000, 'platform_fee_bps' => 3000]);
    $instructors = Instructor::factory()->count(2)->create();
    $payment = paymentWithInstructors($plan, $instructors->all());

    $service = app(RevenueAllocationService::class);

    $first = $service->allocate($payment);
    $second = $service->allocate($payment);

    expect($first)->toBe(2);
    expect($second)->toBe(0); // second run is a no-op
    expect(RevenueAllocation::where('subscription_payment_id', $payment->id)->count())->toBe(2);
});

it('does not allocate when there are no participating instructors', function () {
    $plan = Plan::factory()->create();
    $payment = paymentWithInstructors($plan, []); // no enrollments

    $created = app(RevenueAllocationService::class)->allocate($payment);

    expect($created)->toBe(0);
    expect(RevenueAllocation::where('subscription_payment_id', $payment->id)->count())->toBe(0);
});
