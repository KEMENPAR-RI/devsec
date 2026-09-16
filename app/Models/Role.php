<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Support\Permissions;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named bundle of page permissions (see App\Support\Permissions), attached to
 * users. Roles are the only thing that grants access - a user with no role can
 * sign in but sees nothing but their own profile.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, HasFactory, HasUuid;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'permissions',
        'is_superadmin',
        'is_locked',
    ];

    public function auditEventPrefix(): string
    {
        return 'roles';
    }

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_superadmin' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role) {
            $role->slug = $role->slug ?: Str::slug($role->name);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * A superadmin role answers yes to everything, including permissions that
     * didn't exist when it was created - that's the point of the flag.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->is_superadmin || in_array($permission, $this->permissions ?? [], true);
    }

    /** @return array<int, string> the permissions this role actually holds right now */
    public function grantedPermissions(): array
    {
        return $this->is_superadmin
            ? Permissions::all()
            : Permissions::sanitize($this->permissions);
    }
}
