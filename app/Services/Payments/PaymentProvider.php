<?php

namespace App\Services\Payments;

interface PaymentProvider
{
    /**
     * Attempt to move money to an instructor.
     *
     * The provider de-duplicates on $idempotencyKey: calling pay() twice with the
     * same key moves money at most once and returns the original outcome. This is
     * what makes a retried/duplicated job safe.
     *
     * @throws ProviderTimeoutException when no definitive response is received
     *                                  (the money MAY still have moved).
     */
    public function pay(string $idempotencyKey, int $amountMinor, string $currency, string $destinationRef): ProviderResult;

    /**
     * Ask the provider what actually happened for a given idempotency key. Used
     * to resolve payouts left in an `unknown` state by a timeout.
     */
    public function checkStatus(string $idempotencyKey): ProviderResult;
}
