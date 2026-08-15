<?php

namespace App\Models;

use App\Support\Enums\AccountStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
}
