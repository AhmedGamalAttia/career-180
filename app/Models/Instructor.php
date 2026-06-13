<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Instructor extends Model
{
    /** @use HasFactory<\Database\Factories\InstructorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'payout_account_ref',
        'revenue_share_bps_override',
    ];

    protected function casts(): array
    {
        return [
            'revenue_share_bps_override' => 'integer',
        ];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }
}
