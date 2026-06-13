<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'price_minor',
        'currency',
        'term_days',
        'platform_fee_bps',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'term_days' => 'integer',
            'platform_fee_bps' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
