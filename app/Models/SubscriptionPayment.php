<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPayment extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionPaymentFactory> */
    use HasFactory;

    public const STATUS_CAPTURED = 'captured';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    protected $fillable = [
        'subscription_id',
        'amount_minor',
        'currency',
        'period_start',
        'period_end',
        'paid_at',
        'status',
        'refunded_minor',
        'external_payment_ref',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
