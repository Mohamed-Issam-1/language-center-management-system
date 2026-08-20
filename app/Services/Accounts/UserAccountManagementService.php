<?php

namespace App\Services\Accounts;

use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
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
        private readonly BranchContext $branchContext,
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
                 * Reuse the Person inside the Center when it already
                 * exists. Otherwise create the shared Person identity.
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
                            'center_id' =>
                            $center->id,

                            'national_id_number' =>
                            $nationalIdNumber,
                        ]);
                }

                $roleRecord = $this->roleRecord(
                    $role
                );

                /*
                 * A Person may have separate role-specific accounts,
                 * but never two accounts with the same Role in the
                 * same Center.
                 */
                $duplicate =
                    User::withoutGlobalScopes()
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
                 * Temporary Breeze compatibility.
                 *
                 * LCMS authentication uses account_login_identifier
                 * and recovery_email instead of these legacy fields.
                 */
                $legacyEmail = sprintf(
                    '%s@internal.lcms.invalid',
                    Str::uuid()
                );

                $account = User::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $center->id,

                        'person_id' =>
                        $person->id,

                        'role_id' =>
                        $roleRecord->id,

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

                        'name' => sprintf(
                            '%s Account',
                            $role->label()
                        ),

                        'email' =>
                        $legacyEmail,
                    ]);

                /*
                 * Security/lifecycle fields remain outside broad
                 * model mass assignment.
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

    public function createStudentAccountForStudent(
        User $actor,
        Student $student,
        string $accountLoginIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        return DB::transaction(
            function () use (
                $actor,
                $student,
                $accountLoginIdentifier,
                $recoveryEmail,
                $temporaryPassword
            ): User {
                $centerId =
                    $this->authorizedCenterId(
                        $actor
                    );

                /*
                 * Never authorize using the supplied in-memory
                 * Student state.
                 */
                $student =
                    $this->lockPersistedStudent(
                        $student,
                        $centerId
                    );

                $this->authorizeStudentScopedCreation(
                    $actor,
                    $student
                );

                if (
                    $student->status
                    !== StudentStatus::Active
                ) {
                    throw new DomainException(
                        'An Archived Student must be restored before creating a User Account.'
                    );
                }

                if ($student->user_id !== null) {
                    throw new DomainException(
                        'The Student record is already linked to a User Account.'
                    );
                }

                /*
                 * Person comes from the persisted Student.
                 *
                 * The caller cannot supply a different Person or
                 * National ID to bypass Student ownership.
                 */
                $person = Person::withoutGlobalScopes()
                    ->whereKey(
                        $student->person_id
                    )
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $accountLoginIdentifier = trim(
                    $accountLoginIdentifier
                );

                $recoveryEmail = Str::lower(
                    trim($recoveryEmail)
                );

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

                $roleRecord = $this->roleRecord(
                    SystemRole::Student
                );

                /*
                 * Do not create another Student-role account when
                 * the Person already owns one in the same Center.
                 *
                 * Existing-account linkage is a separate explicit
                 * Student workflow.
                 */
                $duplicate =
                    User::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
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
                        'This Person already has a Student account in the language center.'
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

                $legacyEmail = sprintf(
                    '%s@internal.lcms.invalid',
                    Str::uuid()
                );

                $account =
                    User::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'person_id' =>
                        $person->id,

                        'role_id' =>
                        $roleRecord->id,

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
                             * Temporary Breeze compatibility.
                             */
                        'name' => sprintf(
                            '%s Account',
                            SystemRole::Student
                                ->label()
                        ),

                        'email' =>
                        $legacyEmail,
                    ]);

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

                $account->refresh();

                $account->load([
                    'role',
                    'person',
                    'center',
                ]);

                /*
                 * Both Audit records and the Student linkage live
                 * inside this transaction.
                 *
                 * If any step fails, neither the new account nor
                 * the linkage is allowed to survive.
                 */
                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.created',
                    subject: $account,
                    afterValues: $this->accountAuditValues(
                        $account
                    )
                );

                $student->user_id =
                    $account->id;

                $student->save();
                $student->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.account_linked',
                    subject: $student,
                    beforeValues: [
                        'center_id' =>
                        $student->center_id,

                        'branch_id' =>
                        $student->branch_id,

                        'person_id' =>
                        $student->person_id,

                        'user_id' => null,

                        'status' =>
                        $student->status,
                    ],
                    afterValues: [
                        'center_id' =>
                        $student->center_id,

                        'branch_id' =>
                        $student->branch_id,

                        'person_id' =>
                        $student->person_id,

                        'user_id' =>
                        $student->user_id,

                        'status' =>
                        $student->status,
                    ]
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
                $account =
                    $this->lockPersistedAccount(
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
                 * and lifecycle state cannot be changed here.
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
                        (string)
                        $data['account_login_identifier']
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
                    $recoveryEmail =
                        Str::lower(
                            trim(
                                (string)
                                $data['recovery_email']
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

                /*
                 * Do not produce duplicate Audit history when no
                 * persisted business state changed.
                 */
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
                $account =
                    $this->lockPersistedAccount(
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

                    'deactivated_at' =>
                    null,
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
                        $account
                            ->deactivated_at,
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
                $account =
                    $this->lockPersistedAccount(
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
                 * Account deactivation preserves the User Account,
                 * Student linkage, Person, and operational history.
                 */
                $account->forceFill([
                    'status' =>
                    AccountStatus::Deactivated,

                    'deactivated_at' =>
                    now(),
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
                        $account
                            ->deactivated_at,
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
                $account =
                    $this->lockPersistedAccount(
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
                 * Replacing the stored hash invalidates any previous
                 * temporary credential.
                 */
                $account->forceFill([
                    'password' =>
                    Hash::make(
                        $temporaryPassword
                    ),

                    'must_change_password' =>
                    true,

                    'temporary_password_used_at' =>
                    null,

                    'failed_login_attempts' =>
                    0,

                    'locked_until' =>
                    null,
                ])->save();

                $account->refresh();

                /*
                 * Password material must never be included in Audit
                 * values.
                 */
                $this->audit->record(
                    actor: $actor,
                    actionType: 'user_account.temporary_password_issued',
                    subject: $account,
                    afterValues: [
                        'credential_change_required' =>
                        $account
                            ->must_change_password,

                        'failed_login_attempts' =>
                        $account
                            ->failed_login_attempts,

                        'locked_until' =>
                        $account
                            ->locked_until,
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
        $actorRole =
            $actor->systemRole();

        if (
            $actorRole
            === SystemRole::PlatformOwner
        ) {
            if (
                ! $this->tenant
                    ->isEstablished()
                || ! $this->tenant
                    ->isPlatformScoped()
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

        /*
         * Generic account creation has no Student or Branch target
         * from which Branch ownership can be proven.
         *
         * Branch Manager must therefore use the Student-scoped
         * account-creation workflow.
         */
        if (
            $actorRole
            === SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'Branch Manager must create Student accounts through the Student-scoped account workflow.'
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
        $targetRole =
            $account->systemRole();

        if ($targetRole === null) {
            throw new AuthorizationException(
                'The target account has no valid system role.'
            );
        }

        $actorRole =
            $actor->systemRole();

        if (
            $actorRole
            === SystemRole::PlatformOwner
        ) {
            if (
                ! $this->tenant
                    ->isEstablished()
                || ! $this->tenant
                    ->isPlatformScoped()
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
            if (
                $targetRole
                !== SystemRole::Student
            ) {
                throw new AuthorizationException(
                    'Branch Manager may manage only Student accounts.'
                );
            }

            $center =
                $this->tenant->requireCenter();

            if (
                $actor->center_id
                !== $center->id
                || $account->center_id
                !== $center->id
            ) {
                throw new AuthorizationException(
                    'The Student account is outside the authorized Center scope.'
                );
            }

            Gate::forUser($actor)
                ->authorize(
                    SystemPermission::ManageStudentAccounts
                        ->value
                );

            /*
             * Branch ownership comes exclusively from the persisted
             * Student record linked to the account.
             */
            $student =
                Student::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'user_id',
                    $account->id
                )
                ->lockForUpdate()
                ->first();

            if ($student === null) {
                throw new AuthorizationException(
                    'Branch Manager may manage only accounts linked to Student records.'
                );
            }

            /*
             * Defensive consistency check in addition to the
             * composite database constraints.
             */
            if (
                $account->person_id === null
                || $student->person_id
                !== $account->person_id
            ) {
                throw new AuthorizationException(
                    'The Student Account identity does not match its Student record.'
                );
            }

            $this->ensureBranchManagerStudentScope(
                $actor,
                $student
            );

            return;
        }

        throw new AuthorizationException(
            'The authenticated account cannot manage user accounts.'
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center =
            $this->tenant->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function authorizeStudentScopedCreation(
        User $actor,
        Student $student
    ): void {
        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ManageStudentAccounts
                    ->value
            );

        $actorRole =
            $actor->systemRole();

        /*
         * Center Owner has Center-wide Student Account management.
         * Student ownership has already been constrained by
         * lockPersistedStudent() to the current tenant Center.
         */
        if (
            $actorRole
            === SystemRole::CenterOwner
        ) {
            return;
        }

        if (
            $actorRole
            === SystemRole::BranchManager
        ) {
            $this->ensureBranchManagerStudentScope(
                $actor,
                $student
            );

            return;
        }

        throw new AuthorizationException(
            'The authenticated account cannot create Student accounts through this workflow.'
        );
    }

    private function ensureBranchManagerStudentScope(
        User $actor,
        Student $student
    ): void {
        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'Branch-scoped Student Account management requires a Branch Manager account.'
            );
        }

        /*
         * BranchContext represents the Branch scope established
         * for the current request.
         */
        if (
            ! $this->branchContext
                ->isBranchScoped()
            || $this->branchContext
            ->branchId()
            !== $student->branch_id
        ) {
            throw new AuthorizationException(
                'The Student Account operation is outside the current Branch scope.'
            );
        }

        /*
         * BranchContext alone is not enough.
         *
         * Re-check the active persisted assignment so a stale or
         * fabricated request Branch cannot grant authority.
         */
        $hasAssignment =
            $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $student->center_id
            )
            ->where(
                'branch_id',
                $student->branch_id
            )
            ->exists();

        if (! $hasAssignment) {
            throw new AuthorizationException(
                'The Branch Manager does not have an active assignment for the Student Branch.'
            );
        }
    }

    private function lockPersistedStudent(
        Student $student,
        int $centerId
    ): Student {
        $persistedStudent =
            Student::withoutGlobalScopes()
            ->whereKey(
                $student->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $persistedStudent->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Student record is outside the authorized Center scope.'
            );
        }

        return $persistedStudent;
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
         * Platform Owner administration is platform-scoped, so
         * request global scopes cannot be trusted here.
         *
         * Explicit authorization below applies the required Center,
         * Role, Student, and Branch boundaries.
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
            $account
                ->must_change_password,

            'deactivated_at' =>
            $account
                ->deactivated_at,
        ];
    }
}
