<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\PayoutAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const KIND_SEND = 'send';
    public const KIND_STATUS_CHECK = 'status_check';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILED = 'failed';
    public const RESULT_TIMEOUT = 'timeout';
    public const RESULT_UNKNOWN = 'unknown';

    protected $fillable = [
        'payout_id',
        'kind',
        'result',
        'provider_reference',
        'response_payload',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
