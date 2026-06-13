<?php

use App\Models\Instructor;
use App\Models\Payout;
use App\Models\RevenueAllocation;
use App\Services\BalanceService;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePaymentProvider;

uses(RefreshDatabase::class);

/**
 * An instructor with a single fully-vested allocation worth $shareMinor, i.e. a
 * known amount that is owed and ready to be paid.
 */
function instructorOwed(int $shareMinor): Instructor
{
    $instructor = Instructor::factory()->create();

    RevenueAllocation::factory()
        ->for($instructor)
        ->fullyVested()
        ->create(['share_minor' => $shareMinor, 'pool_minor' => $shareMinor]);

    return $instructor;
}

beforeEach(function () {
    $this->fake = new FakePaymentProvider();
    $this->app->instance(PaymentProvider::class, $this->fake);
    $this->payouts = $this->app->make(PayoutService::class);
    $this->balances = $this->app->make(BalanceService::class);
});

it('pays an owed instructor exactly once on a normal run', function () {
    $instructor = instructorOwed(21000);
    $this->fake->pushSuccess();

    $batch = $this->payouts->openBatch('2026-06');
    expect($this->payouts->planPayouts($batch))->toBe(1);

    $payout = Payout::where('payout_batch_id', $batch->id)->sole();
    $this->payouts->processPayout($payout);

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
    expect($this->fake->totalMoves())->toBe(1);
    expect($this->balances->paidToDate($instructor))->toBe(21000);
    expect($this->balances->availableFor($instructor))->toBe(0);
});

it('never double-pays when the whole payout run is executed twice', function () {
    $instructor = instructorOwed(21000);
    $this->fake->pushSuccess();

    // First run: open batch, plan, process.
    $batch = $this->payouts->openBatch('2026-06');
    $this->payouts->planPayouts($batch);
    $this->payouts->processPayout(Payout::where('payout_batch_id', $batch->id)->sole());

    // Second run for the SAME period: same batch, nothing left to plan or pay.
    $batchAgain = $this->payouts->openBatch('2026-06');
    expect($batchAgain->id)->toBe($batch->id);
    expect($this->payouts->planPayouts($batchAgain))->toBe(0);

    expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
    expect($this->fake->totalMoves())->toBe(1);
    expect($this->balances->paidToDate($instructor))->toBe(21000);
});

it('never double-pays when the same payout job is retried', function () {
    instructorOwed(21000);
    $this->fake->pushSuccess();

    $batch = $this->payouts->openBatch('2026-06');
    $this->payouts->planPayouts($batch);
    $payout = Payout::where('payout_batch_id', $batch->id)->sole();

    // Simulate a crashed-then-retried job: process the same payout repeatedly.
    $this->payouts->processPayout($payout);
    $this->payouts->processPayout($payout->fresh());
    $this->payouts->processPayout($payout->fresh());

    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
    expect($this->fake->totalMoves())->toBe(1);
    // One real send; the retries replayed the provider's recorded outcome.
    expect($this->fake->movesFor($payout->provider_idempotency_key))->toBe(1);
});

it('treats a provider timeout as unknown and resolves it without paying twice', function () {
    $instructor = instructorOwed(21000);
    // The money MOVED but the response was lost.
    $this->fake->pushTimeoutWithMoneyMoved();

    $batch = $this->payouts->openBatch('2026-06');
    $this->payouts->planPayouts($batch);
    $payout = Payout::where('payout_batch_id', $batch->id)->sole();

    // Send times out -> payout parked as unknown, NOT paid, money already moved.
    $this->payouts->processPayout($payout);
    expect($payout->fresh()->status)->toBe(Payout::STATUS_UNKNOWN);
    expect($this->fake->totalMoves())->toBe(1);

    // Reconciliation discovers the truth via a status check and settles as paid.
    $this->payouts->reconcilePayout($payout->fresh());
    expect($payout->fresh()->status)->toBe(Payout::STATUS_PAID);
    expect($this->fake->totalMoves())->toBe(1); // still only one movement
    expect($this->balances->paidToDate($instructor))->toBe(21000);
});

it('returns money to available when a timeout turns out to have moved nothing', function () {
    $instructor = instructorOwed(21000);
    // Timeout where the provider never actually moved money.
    $this->fake->pushTimeoutWithoutMoneyMoved();

    $batch = $this->payouts->openBatch('2026-06');
    $this->payouts->planPayouts($batch);
    $payout = Payout::where('payout_batch_id', $batch->id)->sole();

    $this->payouts->processPayout($payout);
    expect($payout->fresh()->status)->toBe(Payout::STATUS_UNKNOWN);

    // While unknown, the amount is in-flight and must not be re-paid.
    expect($this->balances->availableFor($instructor))->toBe(0);

    // Status check finds nothing -> mark failed -> amount becomes available again.
    $this->payouts->reconcilePayout($payout->fresh());
    expect($payout->fresh()->status)->toBe(Payout::STATUS_FAILED);
    expect($this->fake->totalMoves())->toBe(0);
    expect($this->balances->paidToDate($instructor))->toBe(0);
    expect($this->balances->availableFor($instructor))->toBe(21000);
});

it('excludes in-flight payouts from available so a second batch cannot re-pay', function () {
    $instructor = instructorOwed(21000);

    $batch = $this->payouts->openBatch('2026-06');
    $this->payouts->planPayouts($batch); // creates a pending (in-flight) payout

    // A different period's batch must see nothing available for this instructor.
    $next = $this->payouts->openBatch('2026-07');
    expect($this->payouts->planPayouts($next))->toBe(0);
    expect($this->balances->availableFor($instructor))->toBe(0);
});
