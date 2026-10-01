<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    protected $fillable = [
        'property_id', 'user_id', 'employee_number', 'department_id', 'position', 'employment_status',
        'start_date', 'end_date', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'photo_disk', 'photo_path', 'notes',
    ];

    protected $attributes = ['employment_status' => 'ACTIVE'];

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
