<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePayoutJob;
use App\Models\Payout;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile
                            {--sync : Reconcile immediately instead of queueing}';

    protected $description = 'Resolve payouts left in the unknown state after a provider timeout.';

    public function handle(): int
    {
        $unknown = Payout::query()
            ->where('status', Payout::STATUS_UNKNOWN)
            ->pluck('id');

        if ($unknown->isEmpty()) {
            $this->info('No payouts awaiting reconciliation.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        foreach ($unknown as $payoutId) {
            $sync
                ? ReconcilePayoutJob::dispatchSync($payoutId)
                : ReconcilePayoutJob::dispatch($payoutId);
        }

        $this->info(($sync ? 'Reconciled ' : 'Dispatched reconciliation for ').$unknown->count().' payout(s).');

        return self::SUCCESS;
    }
}
