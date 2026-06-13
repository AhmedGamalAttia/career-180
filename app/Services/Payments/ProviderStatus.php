<?php

namespace App\Services\Payments;

enum ProviderStatus: string
{
    /** The provider confirmed the money was moved. */
    case Succeeded = 'succeeded';

    /** The provider reached and permanently declined the payment. */
    case Failed = 'failed';

    /** The provider has no record of this idempotency key (money never moved). */
    case NotFound = 'not_found';
}
