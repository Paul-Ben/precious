<?php

namespace App\Http\Resources;

use App\Domain\Identity\PermissionCatalog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** Include the flattened permission list (used by /auth/me and user detail). */
    private bool $withPermissions = false;

    public function withPermissions(): static
    {
        $this->withPermissions = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $isSuperAdmin = $this->isSuperAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'must_change_password' => $this->must_change_password,
            'two_factor_enabled' => $this->two_factor_enabled,
            'two_factor_required' => $this->requiresTwoFactor(),
            'is_super_admin' => $isSuperAdmin,
            'roles' => $this->roles->pluck('name')->values(),
            'permissions' => $this->when($this->withPermissions, fn () => $isSuperAdmin
                ? PermissionCatalog::all()
                : $this->getAllPermissions()->pluck('name')->sort()->values()->all()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
