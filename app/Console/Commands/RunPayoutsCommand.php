<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPayoutJob;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunPayoutsCommand extends Command
{
    /**
     * --period : the logical period to pay, e.g. 2026-06 (defaults to this month).
     * --sync   : process payouts inline instead of dispatching to the queue
     *            (handy for the demo / tests).
     */
    protected $signature = 'payouts:run
                            {--period= : Period key to pay, e.g. 2026-06 (defaults to current month)}
                            {--sync : Process payouts immediately instead of queueing}';

    protected $description = 'Open a payout batch, plan instructor payouts, and dispatch them.';

    public function handle(PayoutService $payouts): int
    {
        $period = $this->option('period') ?: Carbon::now()->format('Y-m');

        // Idempotent: re-running for the same period reuses the same batch and
        // never re-pays instructors already covered by it.
        $batch = $payouts->openBatch($period, Carbon::today());
        $this->info("Payout batch [{$batch->period_key}] (#{$batch->id}) ready.");

        $created = $payouts->planPayouts($batch);
        $this->info("Planned {$created} new payout(s).");

        $batch->update([
            'status' => PayoutBatch::STATUS_PROCESSING,
            'started_at' => $batch->started_at ?? Carbon::now(),
        ]);

        $pending = Payout::query()
            ->where('payout_batch_id', $batch->id)
            ->where('status', Payout::STATUS_PENDING)
            ->pluck('id');

        $sync = (bool) $this->option('sync');
        foreach ($pending as $payoutId) {
            $sync
                ? ProcessPayoutJob::dispatchSync($payoutId)
                : ProcessPayoutJob::dispatch($payoutId);
        }

        $this->info(($sync ? 'Processed ' : 'Dispatched ').$pending->count().' payout job(s).');

        return self::SUCCESS;
    }
}
