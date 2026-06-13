<?php

use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Services\BalanceService;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use App\Services\RefundService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakePaymentProvider;

uses(RefreshDatabase::class);

/**
 * One instructor on a 365-day plan priced at 36500 piasters with a 0% platform
 * cut, so the single allocation is worth exactly 100 piasters/day. Returns the
 * [instructor, payment] after capture+allocation.
 */
function annualSetup(Carbon $startsAt): array
{
    $plan = Plan::factory()->create([
        'price_minor' => 36500,
        'term_days' => 365,
        'platform_fee_bps' => 0,
    ]);

    $instructor = Instructor::factory()->create();
    $subscription = Subscription::factory()->forPlan($plan)->startingOn($startsAt)->create();
    Enrollment::factory()->forInstructor($instructor)->for($subscription)->create();

    $payment = app(SubscriptionPaymentService::class)
        ->capture($subscription, 36500, 'cap_'.$subscription->id, $startsAt, allocateInline: true);

    return [$instructor, $payment];
}

afterEach(fn () => Carbon::setTestNow());

it('freezes vesting at the leave date and refunds only the unconsumed portion', function () {
    $start = Carbon::parse('2026-01-01');
    [$instructor, $payment] = annualSetup($start);

    // Student leaves 100 days in.
    $leaveDate = $start->copy()->addDays(100);
    Carbon::setTestNow($leaveDate);

    $refund = app(RefundService::class)->refund($payment, $leaveDate);

    // 100 days consumed @ 100/day = 10000 earned; 265 days unconsumed = 26500 back.
    expect($refund->amount_minor)->toBe(26500);

    $allocation = RevenueAllocation::where('subscription_payment_id', $payment->id)->sole();
    expect($allocation->vesting_stopped_at->toDateString())->toBe($leaveDate->toDateString());

    $balances = app(BalanceService::class);
    // Earned is frozen at the consumed portion, even well after the term.
    Carbon::setTestNow($start->copy()->addDays(300));
    expect($balances->vestedToDate($instructor))->toBe(10000);
    expect($balances->availableFor($instructor))->toBe(10000);

    // Only the unconsumed part of the payment was returned, so the payment is a
    // PARTIAL refund; the subscription itself is terminated (refunded).
    expect($payment->fresh()->status)->toBe(\App\Models\SubscriptionPayment::STATUS_PARTIALLY_REFUNDED);
    expect($payment->fresh()->subscription->status)->toBe(Subscription::STATUS_REFUNDED);
});

it('is idempotent: the same refund key processes once', function () {
    $start = Carbon::parse('2026-01-01');
    [, $payment] = annualSetup($start);
    $leaveDate = $start->copy()->addDays(100);
    Carbon::setTestNow($leaveDate);

    $service = app(RefundService::class);
    $first = $service->refund($payment, $leaveDate, 'refund-key-1');
    $second = $service->refund($payment->fresh(), $leaveDate, 'refund-key-1');

    expect($first->id)->toBe($second->id);
    expect(Refund::where('subscription_payment_id', $payment->id)->count())->toBe(1);
    // refunded_minor must not be applied twice.
    expect($payment->fresh()->refunded_minor)->toBe(26500);
});

it('does not claw back money already paid to the instructor', function () {
    $start = Carbon::parse('2026-01-01');
    [$instructor, $payment] = annualSetup($start);

    // Pay the instructor what is vested at day 100 (= 10000) via a normal payout.
    $atDay100 = $start->copy()->addDays(100);
    Carbon::setTestNow($atDay100);

    $fake = new FakePaymentProvider();
    $this->app->instance(PaymentProvider::class, $fake);
    $fake->pushSuccess();

    $payouts = $this->app->make(PayoutService::class);
    $batch = $payouts->openBatch('2026-04');
    $payouts->planPayouts($batch);
    $payout = Payout::where('payout_batch_id', $batch->id)->sole();
    expect($payout->amount_minor)->toBe(10000);
    $payouts->processPayout($payout);

    // The student now leaves, effective the same day.
    app(RefundService::class)->refund($payment->fresh(), $atDay100);

    $balances = $this->app->make(BalanceService::class);
    // Already paid stays paid; nothing is owed; nothing goes negative.
    expect($balances->paidToDate($instructor))->toBe(10000);
    expect($balances->availableFor($instructor))->toBe(0);
    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
});
