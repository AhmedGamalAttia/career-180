<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    /** @use HasFactory<\Database\Factories\PayoutFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    /** States that still tie up money and must NOT be re-created in a new batch. */
    public const IN_FLIGHT_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_UNKNOWN,
    ];

    protected $fillable = [
        'payout_batch_id',
        'instructor_id',
        'amount_minor',
        'currency',
        'status',
        'attempts',
        'provider_idempotency_key',
        'external_payout_ref',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_FAILED], true);
    }
}
