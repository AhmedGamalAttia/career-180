<?php

namespace Tests\Support;

use App\Services\Payments\PaymentProvider;
use App\Services\Payments\ProviderResult;
use App\Services\Payments\ProviderTimeoutException;

/**
 * Deterministic test double for the payment provider.
 *
 * Behaviours for NEW idempotency keys are scripted via the push* methods (default
 * is success). Crucially it models the same provider-side idempotency as the real
 * mock: a repeated key replays its recorded outcome, and `movesFor()` counts how
 * many times money ACTUALLY moved for a key — so tests can assert "paid exactly
 * once" no matter how many times the job ran.
 */
class FakePaymentProvider implements PaymentProvider
{
    /** @var list<string> queued behaviours for unseen keys */
    private array $script = [];

    /** @var array<string, ProviderResult> provider-side ledger keyed by idempotency key */
    private array $ledger = [];

    /** @var array<string, int> number of real money movements per key */
    private array $moves = [];

    public int $payCalls = 0;
    public int $statusCalls = 0;

    public function pushSuccess(): static
    {
        $this->script[] = 'success';

        return $this;
    }

    public function pushFailure(): static
    {
        $this->script[] = 'failure';

        return $this;
    }

    public function pushTimeoutWithMoneyMoved(): static
    {
        $this->script[] = 'timeout_paid';

        return $this;
    }

    public function pushTimeoutWithoutMoneyMoved(): static
    {
        $this->script[] = 'timeout_unpaid';

        return $this;
    }

    public function pay(string $idempotencyKey, int $amountMinor, string $currency, string $destinationRef): ProviderResult
    {
        $this->payCalls++;

        // Provider-side idempotency: replay the recorded outcome, no new movement.
        if (isset($this->ledger[$idempotencyKey])) {
            return $this->ledger[$idempotencyKey];
        }

        $behaviour = array_shift($this->script) ?? 'success';

        return match ($behaviour) {
            'success' => $this->record($idempotencyKey, ProviderResult::succeeded("fake_{$idempotencyKey}"), moved: true),
            'failure' => $this->record($idempotencyKey, ProviderResult::failed(), moved: false),
            'timeout_paid' => $this->timeout($idempotencyKey, recordSuccess: true),
            'timeout_unpaid' => $this->timeout($idempotencyKey, recordSuccess: false),
        };
    }

    public function checkStatus(string $idempotencyKey): ProviderResult
    {
        $this->statusCalls++;

        return $this->ledger[$idempotencyKey] ?? ProviderResult::notFound();
    }

    public function movesFor(string $idempotencyKey): int
    {
        return $this->moves[$idempotencyKey] ?? 0;
    }

    public function totalMoves(): int
    {
        return array_sum($this->moves);
    }

    private function timeout(string $idempotencyKey, bool $recordSuccess): never
    {
        if ($recordSuccess) {
            $this->record($idempotencyKey, ProviderResult::succeeded("fake_{$idempotencyKey}"), moved: true);
        }

        throw new ProviderTimeoutException($idempotencyKey);
    }

    private function record(string $idempotencyKey, ProviderResult $result, bool $moved): ProviderResult
    {
        $this->ledger[$idempotencyKey] = $result;
        if ($moved) {
            $this->moves[$idempotencyKey] = ($this->moves[$idempotencyKey] ?? 0) + 1;
        }

        return $result;
    }
}
