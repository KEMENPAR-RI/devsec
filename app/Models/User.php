<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasUuid;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'banned_at',
        'banned_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Whether this user may do something, e.g. hasPermission('situs.update').
     *
     * Access comes only from the role: a user with no role has no permissions at
     * all, which is deliberately the safe default for a freshly created account
     * whose role hasn't been picked yet. Every Gate in the app funnels through
     * here - see AppServiceProvider::boot().
     */
    public function hasPermission(string $permission): bool
    {
        return ! $this->isBanned() && (bool) $this->role?->hasPermission($permission);
    }

    public function isSuperadmin(): bool
    {
        return (bool) $this->role?->is_superadmin;
    }

    /** Sends the reset link through the app's own email template, not Laravel's. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * A banned account is blocked outright rather than merely stripped of
     * permissions: even the profile page is closed to it, so hasPermission() has
     * to say no regardless of what the role grants.
     */
    public function scopeBanned(Builder $query): Builder
    {
        return $query->whereNotNull('banned_at');
    }

    public function scopeNotBanned(Builder $query): Builder
    {
        return $query->whereNull('banned_at');
    }

    /**
     * Up to two uppercase initials derived from the user's name, for avatar badges.
     */
    public function initials(): string
    {
        return collect(explode(' ', $this->name))
            ->filter()
            ->map(fn (string $word) => Str::upper($word[0]))
            ->take(2)
            ->implode('');
    }
}
