<?php

namespace App\Http\Resources\Staff;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member: login account + staff profile (P23). Load `staffProfile.department` and `roles`.
 *
 * @mixin User
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->staffProfile;
        // Personal details only for people who may view staff records (not schedule-only roles).
        $private = (bool) $request->user()?->can('staff.view');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'account_status' => $this->status->value,
            'roles' => $this->roles->pluck('name')->values(),
            'employee_number' => $p?->employee_number,
            'department' => $p?->department ? ['id' => $p->department->id, 'name' => $p->department->name] : null,
            'position' => $p?->position,
            'employment_status' => $p?->employment_status->value,
            'employment_status_label' => $p?->employment_status->label(),
            'start_date' => $p?->start_date?->toDateString(),
            'end_date' => $p?->end_date?->toDateString(),
            'address' => $this->when($private, fn () => $p?->address),
            'emergency_contact_name' => $this->when($private, fn () => $p?->emergency_contact_name),
            'emergency_contact_phone' => $this->when($private, fn () => $p?->emergency_contact_phone),
            'has_photo' => (bool) $p?->photo_path,
            'photo_version' => $p?->photo_path ? substr(md5($p->photo_path), 0, 8) : null,
            'notes' => $this->when($private, fn () => $p?->notes),
        ];
    }
}
