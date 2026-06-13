<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    /** @use HasFactory<\Database\Factories\RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_payment_id',
        'subscription_id',
        'amount_minor',
        'currency',
        'effective_date',
        'reason',
        'refunded_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_date' => 'date',
            'refunded_at' => 'datetime',
        ];
    }

    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
