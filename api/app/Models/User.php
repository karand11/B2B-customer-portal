<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    // company_id and roles are never mass-assignable; see provision().
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Lowercase and trim so the unique index behaves case-insensitively. */
    protected function email(): Attribute
    {
        return Attribute::set(fn ($value) => is_string($value) ? mb_strtolower(trim($value)) : $value);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * The only sanctioned way to create/update a user with a role (seeders, future invite flow).
     * Enforces: customer => company required; staff/admin => no company; exactly one role.
     *
     * @param  array<string, mixed>  $attributes  name, password, email_verified_at, ...
     */
    public static function provision(string $role, string $email, array $attributes, ?Company $company = null): static
    {
        if (! in_array($role, ['customer', 'staff', 'admin'], true)) {
            throw new InvalidArgumentException("Unknown role [$role].");
        }
        if (($role === 'customer') !== ($company !== null)) {
            throw new InvalidArgumentException('Customers need a company; staff and admin must not have one.');
        }

        $user = static::firstOrNew(['email' => $email]);
        $user->forceFill($attributes + ['company_id' => $company?->id])->save();
        $user->syncRoles($role);

        return $user;
    }
}
