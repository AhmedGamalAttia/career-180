<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'student_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'canceled_at' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * Distinct instructor ids that share in this subscription's revenue,
     * ordered deterministically so any rounding remainder is distributed
     * the same way every time allocation runs.
     *
     * @return list<int>
     */
    public function participatingInstructorIds(): array
    {
        return $this->enrollments()
            ->select('instructor_id')
            ->distinct()
            ->orderBy('instructor_id')
            ->pluck('instructor_id')
            ->all();
    }
}
