<?php

namespace App\Models;

use App\Support\Authorization\RolePermissionRegistry;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Traits\HasCenterScope;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasCenterScope, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'center_id',
        'person_id',
        'role_id',
        'account_login_identifier',
        'recovery_email',
        'status',
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
            'status' => AccountStatus::class,
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'temporary_password_used_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function systemRole(): ?SystemRole
    {
        $this->loadMissing('role');

        if ($this->role === null) {
            return null;
        }

        return SystemRole::tryFrom(
            $this->role->code
        );
    }

    public function hasSystemRole(
        SystemRole $role
    ): bool {
        return $this->systemRole() === $role;
    }

    public function hasPermission(
        SystemPermission $permission
    ): bool {
        $role = $this->systemRole();

        if ($role === null) {
            return false;
        }

        return RolePermissionRegistry::allows(
            $role,
            $permission
        );
    }

    public function canAccessPanel(
        Panel $panel
    ): bool {
        /*
         * LCMS currently exposes one internal Filament panel.
         *
         * Panel access is deliberately narrower than application
         * authentication: Teacher and Student use the custom
         * application UI rather than the internal administration
         * panel.
         */
        if ($panel->getId() !== 'admin') {
            return false;
        }

        /*
         * An existing authenticated session must not preserve
         * Filament access after the account has been deactivated
         * or moved to another non-active lifecycle state.
         */
        if ($this->status !== AccountStatus::Active) {
            return false;
        }

        /*
         * must_change_password is intentionally not checked here.
         *
         * The shared forced-password middleware redirects such an
         * account to the existing password-change workflow instead
         * of turning that lifecycle requirement into a 403 response.
         */
        return in_array(
            $this->systemRole(),
            [
                SystemRole::PlatformOwner,
                SystemRole::CenterOwner,
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
            ],
            true
        );
    }

    public function reviewedRegistrationRequests(): HasMany
    {
        return $this->hasMany(
            RegistrationRequest::class,
            'reviewed_by_user_id'
        );
    }

    public function branchManagerAssignments(): HasMany
    {
        return $this->hasMany(
            BranchManagerAssignment::class
        );
    }

    public function activeBranchManagerAssignment(): HasOne
    {
        return $this->hasOne(
            BranchManagerAssignment::class
        )
            ->where('active_marker', 1)
            ->whereNull('ended_at');
    }

    public function financeEmployeeAssignments(): HasMany
    {
        return $this->hasMany(
            FinanceEmployeeAssignment::class
        );
    }

    public function student(): HasOne
    {
        return $this->hasOne(
            Student::class
        );
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(
            Teacher::class
        );
    }

    public function branchManager(): HasOne
    {
        return $this->hasOne(
            BranchManager::class
        );
    }

    public function financeEmployee(): HasOne
    {
        return $this->hasOne(
            FinanceEmployee::class
        );
    }

    public function activeFinanceEmployeeAssignment(): HasOne
    {
        return $this->hasOne(
            FinanceEmployeeAssignment::class
        )
            ->where('active_marker', 1)
            ->whereNull('ended_at');
    }
}
