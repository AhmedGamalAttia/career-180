<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Thrown when a payment request gets no definitive response. Critically, this
 * means the outcome is UNKNOWN: the provider may have moved the money before the
 * connection dropped, or it may never have received the request at all. Callers
 * must NOT treat this as a failure and retry blindly — they must reconcile via a
 * status check using the same idempotency key.
 */
class ProviderTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Payment provider timed out for idempotency key [{$idempotencyKey}]; outcome unknown.");
    }
}
