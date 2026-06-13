<?php

namespace App\Services\Payments;

/**
 * A definitive answer from the payment provider. The absence of an answer
 * (a network timeout) is NOT represented here — that is a ProviderTimeoutException.
 */
final class ProviderResult
{
    public function __construct(
        public readonly ProviderStatus $status,
        public readonly ?string $reference = null,
    ) {
    }

    public static function succeeded(string $reference): self
    {
        return new self(ProviderStatus::Succeeded, $reference);
    }

    public static function failed(?string $reference = null): self
    {
        return new self(ProviderStatus::Failed, $reference);
    }

    public static function notFound(): self
    {
        return new self(ProviderStatus::NotFound);
    }
}
