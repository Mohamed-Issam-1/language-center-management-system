<?php

namespace App\Services\Accounts;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserAccountManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function create(
        User $actor,
        Center $center,
        string $nationalIdNumber,
        SystemRole $role,
        string $accountLoginIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $center,
                $nationalIdNumber,
                $role,
                $accountLoginIdentifier,
                $recoveryEmail,
                $temporaryPassword
            ): User {
                $center = $this->lockPersistedCenter(
                    $center
                );

                $this->authorizeCreation(
                    $actor,
                    $center,
                    $role
                );

                $nationalIdNumber = trim(
                    $nationalIdNumber
                );

                $accountLoginIdentifier = trim(
                    $accountLoginIdentifier
                );

                $recoveryEmail = Str::lower(
                    trim($recoveryEmail)
                );

                if ($nationalIdNumber === '') {
                    throw new DomainException(
                        'A National ID Number is required.'
                    );
                }

                if ($accountLoginIdentifier === '') {
                    throw new DomainException(
                        'An account login identifier is required.'
                    );
                }

                if ($recoveryEmail === '') {
                    throw new DomainException(
                        'A recovery email address is required.'
                    );
                }

                if ($temporaryPassword === '') {
                    throw new DomainException(
                        'A temporary password is required.'
                    );
                }

                /*
                 * The SRS requires account creation to reuse the
                 * matching Person within the Center when one exists,
                 * or create a new Person when none exists.
                 *
                 * Person has a Center global scope, but Platform Owner
                 * account creation is platform-scoped. Therefore this
                 * operation uses explicit persisted Center conditions
                 * rather than relying on the request query scope.
                 */
                $person = Person::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $center->id
                    )
                    ->where(
                        'national_id_number',
                        $nationalIdNumber
                    )
                    ->lockForUpdate()
                    ->first();

                if ($person === null) {
                    $person = Person::withoutGlobalScopes()
                        ->create([
                            'center_id' => $center->id,
                            'national_id_number' =>
                            $nationalIdNumber,
                        ]);
                }

                $roleRecord = $this->roleRecord(
                    $role
                );

                /*
                 * Never use a supplied role_id.
                 *
                 * The fixed SystemRole is resolved to its persisted
                 * Role record by the service.
                 */
                $duplicate = User::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $center->id
                    )
                    ->where(
                        'person_id',
                        $person->id
                    )
                    ->where(
                        'role_id',
                        $roleRecord->id
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw new DomainException(
                        sprintf(
                            'This Person already has a %s account in the language center.',
                            $role->label()
                        )
                    );
                }

                if (
                    User::withoutGlobalScopes()
                    ->where(
                        'account_login_identifier',
                        $accountLoginIdentifier
                    )
                    ->exists()
                ) {
                    throw new DomainException(
                        'The account login identifier is already in use.'
                    );
                }

                /*
                 * name/email are temporary Breeze compatibility
                 * columns from the original authentication scaffold.
                 *
                 * They are not used as the LCMS authentication
                 * identity or recovery-email contract.
                 *
                 * email receives a unique non-deliverable internal
                 * value so different role-specific accounts may
                 * legitimately share one recovery_email.
                 */
                $legacyEmail = sprintf(
                    '%s@internal.lcms.invalid',
                    Str::uuid()
                );

                $account = User::withoutGlobalScopes()
                    ->create([
                        'center_id' => $center->id,
                        'person_id' => $person->id,
                        'role_id' => $roleRecord->id,

                        'account_login_identifier' =>
                        $accountLoginIdentifier,

                        'recovery_email' =>
                        $recoveryEmail,

                        'status' =>
                        AccountStatus::Active,

                        'password' =>
                        Hash::make(
                            $temporaryPassword
                        ),

                        /*
                         * Temporary Breeze compatibility only.
                         */
                        'name' => sprintf(
                            '%s Account',
                            $role->label()
                        ),

                        'email' => $legacyEmail,
                    ]);

                /*
                 * Authentication and lifecycle state must not be
                 * broadly mass assignable on the User model.
                 *
                 * Administrative account creation owns these fields
                 * explicitly and therefore sets them with forceFill().
                 */
                $account->forceFill([
                    'must_change_password' => true,

                    'temporary_password_used_at' =>
                    null,

                    'failed_login_attempts' => 0,

                    'locked_until' => null,

                    'last_login_at' => null,

                    'password_changed_at' => null,

                    'deactivated_at' => null,
                ])->save();

                /*
                 * Re-read persisted state before authorization/audit
                 * consumers inspect the newly created account.
                 */
                $account->refresh();

                $account->load([
                    'role',
                    'person',
                    'center',
                ]);

                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.created',
                    subject: $account,
                    afterValues: $this->accountAuditValues(
                        $account
                    )
                );

                return $account;
            },
            3
        );
    }

    public function update(
        User $actor,
        User $account,
        array $attributes
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $account,
                $attributes
            ): User {
                $account = $this->lockPersistedAccount(
                    $account
                );

                $this->authorizeManagement(
                    $actor,
                    $account
                );

                $beforeValues =
                    $this->accountAuditValues(
                        $account
                    );

                /*
                 * center_id, person_id, role_id, status, password,
                 * and lifecycle state are intentionally excluded.
                 *
                 * Role changes require another role-specific account.
                 * Lifecycle changes use activate()/deactivate().
                 * Password issuance uses issueTemporaryPassword().
                 */
                $data = Arr::only(
                    $attributes,
                    [
                        'account_login_identifier',
                        'recovery_email',
                    ]
                );

                if (
                    array_key_exists(
                        'account_login_identifier',
                        $data
                    )
                ) {
                    $identifier = trim(
                        (string) $data['account_login_identifier']
                    );

                    if ($identifier === '') {
                        throw new DomainException(
                            'An account login identifier is required.'
                        );
                    }

                    $data['account_login_identifier'] = $identifier;
                }

                if (
                    array_key_exists(
                        'recovery_email',
                        $data
                    )
                ) {
                    $recoveryEmail = Str::lower(
                        trim(
                            (string) $data['recovery_email']
                        )
                    );

                    if ($recoveryEmail === '') {
                        throw new DomainException(
                            'A recovery email address is required.'
                        );
                    }

                    $data['recovery_email'] = $recoveryEmail;
                }

                $account->fill(
                    $data
                );

                if (! $account->isDirty()) {
                    return $account;
                }

                if (
                    $account->isDirty(
                        'account_login_identifier'
                    )
                    && User::withoutGlobalScopes()
                    ->where(
                        'account_login_identifier',
                        $account
                            ->account_login_identifier
                    )
                    ->whereKeyNot(
                        $account->id
                    )
                    ->exists()
                ) {
                    throw new DomainException(
                        'The account login identifier is already in use.'
                    );
                }

                $account->save();
                $account->refresh();
                $account->loadMissing('role');

                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.updated',
                    subject: $account,
                    beforeValues: $beforeValues,
                    afterValues: $this->accountAuditValues(
                        $account
                    )
                );

                return $account;
            },
            3
        );
    }

    public function activate(
        User $actor,
        User $account
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $account
            ): User {
                $account = $this->lockPersistedAccount(
                    $account
                );

                $this->authorizeManagement(
                    $actor,
                    $account
                );

                if (
                    $account->status
                    === AccountStatus::Active
                ) {
                    return $account;
                }

                $beforeValues = [
                    'status' =>
                    $account->status,
                    'deactivated_at' =>
                    $account->deactivated_at,
                ];

                $account->forceFill([
                    'status' =>
                    AccountStatus::Active,
                    'deactivated_at' => null,
                ])->save();

                $account->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.activated',
                    subject: $account,
                    beforeValues: $beforeValues,
                    afterValues: [
                        'status' =>
                        $account->status,
                        'deactivated_at' =>
                        $account->deactivated_at,
                    ]
                );

                return $account;
            },
            3
        );
    }

    public function deactivate(
        User $actor,
        User $account
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $account
            ): User {
                $account = $this->lockPersistedAccount(
                    $account
                );

                $this->authorizeManagement(
                    $actor,
                    $account
                );

                if (
                    $account->status
                    === AccountStatus::Deactivated
                ) {
                    return $account;
                }

                $beforeValues = [
                    'status' =>
                    $account->status,
                    'deactivated_at' =>
                    $account->deactivated_at,
                ];

                /*
                 * Deactivation preserves the account and every
                 * historical relationship.
                 *
                 * Existing protected requests are already rejected
                 * by tenant.context because only Active accounts may
                 * continue protected activity.
                 */
                $account->forceFill([
                    'status' =>
                    AccountStatus::Deactivated,
                    'deactivated_at' => now(),
                ])->save();

                $account->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.deactivated',
                    subject: $account,
                    beforeValues: $beforeValues,
                    afterValues: [
                        'status' =>
                        $account->status,
                        'deactivated_at' =>
                        $account->deactivated_at,
                    ]
                );

                return $account;
            },
            3
        );
    }

    public function issueTemporaryPassword(
        User $actor,
        User $account,
        string $temporaryPassword
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $account,
                $temporaryPassword
            ): User {
                $account = $this->lockPersistedAccount(
                    $account
                );

                $this->authorizeManagement(
                    $actor,
                    $account
                );

                if (
                    $account->status
                    !== AccountStatus::Active
                ) {
                    throw new DomainException(
                        'A temporary password may be issued only for an active account.'
                    );
                }

                if ($temporaryPassword === '') {
                    throw new DomainException(
                        'A temporary password is required.'
                    );
                }

                /*
                 * Issuing another temporary password invalidates
                 * any previous temporary password because the
                 * stored password hash is replaced.
                 */
                $account->forceFill([
                    'password' =>
                    Hash::make(
                        $temporaryPassword
                    ),

                    'must_change_password' => true,

                    'temporary_password_used_at' =>
                    null,

                    /*
                     * Administrative recovery should not leave a
                     * previous failed-login lock blocking the newly
                     * issued credential.
                     */
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $account->refresh();

                /*
                 * Password material is intentionally absent from
                 * Audit payloads.
                 */
                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.temporary_password_issued',
                    subject: $account,
                    afterValues: [
                        /*
                        * Audit field names intentionally avoid the word
                        * "password" so the generic sensitive-value sanitizer
                        * can continue protecting actual credential material.
                        */
                        'credential_change_required' =>
                        $account->must_change_password,

                        'failed_login_attempts' =>
                        $account
                            ->failed_login_attempts,

                        'locked_until' =>
                        $account->locked_until,
                    ]
                );

                return $account;
            },
            3
        );
    }

    private function authorizeCreation(
        User $actor,
        Center $center,
        SystemRole $targetRole
    ): void {
        $actorRole = $actor->systemRole();

        if (
            $actorRole
            === SystemRole::PlatformOwner
        ) {
            if (
                ! $this->tenant->isEstablished()
                || ! $this->tenant->isPlatformScoped()
            ) {
                throw new AuthorizationException(
                    'Center Owner account management requires platform scope.'
                );
            }

            Gate::forUser($actor)
                ->authorize(
                    SystemPermission::ManageCenterOwnerAccounts
                        ->value
                );

            if (
                $actor->center_id !== null
                || $actor->person_id !== null
            ) {
                throw new AuthorizationException(
                    'Invalid Platform Owner account scope.'
                );
            }

            if (
                $targetRole
                !== SystemRole::CenterOwner
            ) {
                throw new AuthorizationException(
                    'Platform Owner may create only Center Owner accounts through this workflow.'
                );
            }

            return;
        }

        if (
            $actorRole
            === SystemRole::CenterOwner
        ) {
            $tenantCenter =
                $this->tenant->requireCenter();

            if (
                $actor->center_id
                !== $tenantCenter->id
                || $center->id
                !== $tenantCenter->id
            ) {
                throw new AuthorizationException(
                    'Authenticated account, target Center, and tenant context do not match.'
                );
            }

            if (
                $targetRole
                === SystemRole::Student
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStudentAccounts
                            ->value
                    );

                return;
            }

            if (
                in_array(
                    $targetRole,
                    [
                        SystemRole::BranchManager,
                        SystemRole::FinanceEmployee,
                        SystemRole::Teacher,
                    ],
                    true
                )
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStaffAccounts
                            ->value
                    );

                return;
            }

            throw new AuthorizationException(
                'Center Owner cannot assign the requested role.'
            );
        }

        if (
            $actorRole
            === SystemRole::BranchManager
        ) {
            /*
             * Branch Manager may manage Student accounts only
             * inside the assigned Branch.
             *
             * The Student branch-linked domain record does not
             * exist yet, so branch ownership cannot currently be
             * proven safely. This path remains fail-closed until
             * the Student domain foundation is implemented.
             */
            throw new AuthorizationException(
                'Branch Manager Student-account management requires the Student branch-scope foundation.'
            );
        }

        throw new AuthorizationException(
            'The authenticated account cannot create user accounts.'
        );
    }

    private function authorizeManagement(
        User $actor,
        User $account
    ): void {
        $targetRole = $account->systemRole();

        if ($targetRole === null) {
            throw new AuthorizationException(
                'The target account has no valid system role.'
            );
        }

        $actorRole = $actor->systemRole();

        if (
            $actorRole
            === SystemRole::PlatformOwner
        ) {
            if (
                ! $this->tenant->isEstablished()
                || ! $this->tenant->isPlatformScoped()
            ) {
                throw new AuthorizationException(
                    'Center Owner account management requires platform scope.'
                );
            }

            Gate::forUser($actor)
                ->authorize(
                    SystemPermission::ManageCenterOwnerAccounts
                        ->value
                );

            if (
                $targetRole
                !== SystemRole::CenterOwner
            ) {
                throw new AuthorizationException(
                    'Platform Owner may manage only Center Owner accounts through this workflow.'
                );
            }

            if ($account->center_id === null) {
                throw new AuthorizationException(
                    'The Center Owner account has no language center.'
                );
            }

            return;
        }

        if (
            $actorRole
            === SystemRole::CenterOwner
        ) {
            $center =
                $this->tenant->requireCenter();

            if (
                $actor->center_id
                !== $center->id
                || $account->center_id
                !== $center->id
            ) {
                throw new AuthorizationException(
                    'The target account is outside the authorized Center scope.'
                );
            }

            if (
                $targetRole
                === SystemRole::Student
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStudentAccounts
                            ->value
                    );

                return;
            }

            if (
                in_array(
                    $targetRole,
                    [
                        SystemRole::BranchManager,
                        SystemRole::FinanceEmployee,
                        SystemRole::Teacher,
                    ],
                    true
                )
            ) {
                Gate::forUser($actor)
                    ->authorize(
                        SystemPermission::ManageStaffAccounts
                            ->value
                    );

                return;
            }

            throw new AuthorizationException(
                'Center Owner cannot manage the requested account role.'
            );
        }

        if (
            $actorRole
            === SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'Branch Manager Student-account management requires the Student branch-scope foundation.'
            );
        }

        throw new AuthorizationException(
            'The authenticated account cannot manage user accounts.'
        );
    }

    private function lockPersistedCenter(
        Center $center
    ): Center {
        return Center::query()
            ->whereKey(
                $center->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockPersistedAccount(
        User $account
    ): User {
        /*
         * Re-read without request global scopes because Platform
         * Owner account administration is intentionally platform
         * scoped. Authorization below applies the explicit target
         * Center and role boundary.
         */
        return User::withoutGlobalScopes()
            ->whereKey(
                $account->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function roleRecord(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountAuditValues(
        User $account
    ): array {
        return [
            'center_id' =>
            $account->center_id,

            'person_id' =>
            $account->person_id,

            'role' =>
            $account->systemRole(),

            'account_login_identifier' =>
            $account
                ->account_login_identifier,

            'status' =>
            $account->status,

            'credential_change_required' =>
            $account->must_change_password,

            'deactivated_at' =>
            $account->deactivated_at,
        ];
    }
}
