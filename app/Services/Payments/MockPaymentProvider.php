<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * A stand-in for a real, unreliable payout provider.
 *
 * Each call randomly:
 *   - succeeds,
 *   - fails permanently, or
 *   - times out — and within that, the money may or may not have actually moved
 *     before the connection dropped ("timed out after already succeeding").
 *
 * The crucial realistic property: the provider keeps its OWN ledger keyed by the
 * idempotency key. It records the outcome BEFORE it could time out, so a retried
 * call with the same key replays the recorded outcome instead of charging again.
 * That ledger is what makes retries and duplicate jobs safe on our side.
 *
 * The ledger lives in the cache so it survives across queued jobs / processes,
 * exactly as an external provider's records would.
 */
class MockPaymentProvider implements PaymentProvider
{
    private const LEDGER_PREFIX = 'mock_provider:';
    private const LEDGER_TTL_DAYS = 30;

    public function __construct(private readonly Cache $cache)
    {
    }

    public function pay(string $idempotencyKey, int $amountMinor, string $currency, string $destinationRef): ProviderResult
    {
        // Provider-side idempotency: if we've seen this key, replay the recorded
        // outcome. Money is never moved a second time.
        if ($recorded = $this->recordedOutcome($idempotencyKey)) {
            return $recorded;
        }

        $roll = random_int(1, 100);

        // ~70%: clean success.
        if ($roll <= 70) {
            return $this->record($idempotencyKey, ProviderResult::succeeded($this->reference()));
        }

        // ~15%: permanent, definitive failure (money NOT moved).
        if ($roll <= 85) {
            return $this->record($idempotencyKey, ProviderResult::failed());
        }

        // ~15%: timeout. Half the time the money moved before the timeout (we
        // record success, then throw); half the time it never moved (we record
        // nothing, then throw). Either way the caller gets no answer and must
        // reconcile.
        if (random_int(0, 1) === 1) {
            $this->record($idempotencyKey, ProviderResult::succeeded($this->reference()));
        }

        throw new ProviderTimeoutException($idempotencyKey);
    }

    public function checkStatus(string $idempotencyKey): ProviderResult
    {
        return $this->recordedOutcome($idempotencyKey) ?? ProviderResult::notFound();
    }

    private function recordedOutcome(string $idempotencyKey): ?ProviderResult
    {
        $data = $this->cache->get(self::LEDGER_PREFIX.$idempotencyKey);

        if ($data === null) {
            return null;
        }

        return new ProviderResult(ProviderStatus::from($data['status']), $data['reference'] ?? null);
    }

    private function record(string $idempotencyKey, ProviderResult $result): ProviderResult
    {
        $this->cache->put(
            self::LEDGER_PREFIX.$idempotencyKey,
            ['status' => $result->status->value, 'reference' => $result->reference],
            now()->addDays(self::LEDGER_TTL_DAYS),
        );

        return $result;
    }

    private function reference(): string
    {
        return 'mock_'.Str::lower(Str::random(20));
    }
}
