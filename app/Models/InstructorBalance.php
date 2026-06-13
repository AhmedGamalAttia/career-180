<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorBalance extends Model
{
    public const UPDATED_AT = 'updated_at';
    public const CREATED_AT = null;

    protected $primaryKey = 'instructor_id';
    public $incrementing = false;

    protected $fillable = [
        'instructor_id',
        'lifetime_vested_minor',
        'lifetime_paid_minor',
        'in_flight_minor',
        'available_minor',
    ];

    protected function casts(): array
    {
        return [
            'lifetime_vested_minor' => 'integer',
            'lifetime_paid_minor' => 'integer',
            'in_flight_minor' => 'integer',
            'available_minor' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
