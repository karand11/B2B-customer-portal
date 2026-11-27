<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /** Same as the column defaults, so a freshly built model (factory, forceFill) is already active at epoch 1 without a refresh. */
    protected $attributes = [
        'active' => true,
        'session_epoch' => 1,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'session_epoch' => 'integer',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
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

    /** Quotes this user created. */
    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class, 'created_by_user_id');
    }

    // hasRole() uses loadMissing('roles'), which is an explicit load (no lazy-loading violation).
    public function isCustomer(): bool
    {
        return $this->hasRole('customer');
    }

    /** staff or admin */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(['staff', 'admin']);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** The one role (users have exactly one); loadMissing is an explicit load, not a lazy-loading violation. */
    public function primaryRole(): ?string
    {
        return $this->loadMissing('roles')->roles->first()?->name;
    }

    /** inactive (deactivated or revoked) > pending (invited, not accepted) > active. */
    public function status(): string
    {
        return match (true) {
            ! $this->active => 'inactive',
            $this->invited_at !== null && $this->invitation_accepted_at === null => 'pending',
            default => 'active',
        };
    }

    /** Only active users can sign in or keep a session. */
    public function canSignIn(): bool
    {
        return $this->status() === 'active';
    }

    /** Invited and never accepted (pending, or revoked and so inactive). */
    public function hasOpenInvitation(): bool
    {
        return $this->invited_at !== null && $this->invitation_accepted_at === null;
    }

    /**
     * The one place that sets role and company (provision, invitations, the admin assignment endpoint).
     * Enforces: customer => company required; staff/admin => no company; exactly one role. Saves the model.
     */
    public function assign(string $role, ?Company $company): static
    {
        if (! in_array($role, ['customer', 'staff', 'admin'], true)) {
            throw new InvalidArgumentException("Unknown role [$role].");
        }
        if (($role === 'customer') !== ($company !== null)) {
            throw new InvalidArgumentException('Customers need a company; staff and admin must not have one.');
        }

        $this->forceFill(['company_id' => $company?->id])->save();
        $this->syncRoles($role);
        $this->unsetRelation('roles')->unsetRelation('company');

        return $this;
    }

    /**
     * The only sanctioned way to create/update a user with a role (seeders, invite flow).
     *
     * @param  array<string, mixed>  $attributes  name, password, email_verified_at, ...
     */
    public static function provision(string $role, string $email, array $attributes, ?Company $company = null): static
    {
        return static::firstOrNew(['email' => $email])->forceFill($attributes)->assign($role, $company);
    }
}
