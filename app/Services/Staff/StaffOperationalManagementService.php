<?php

namespace App\Services\Staff;

use App\Models\BranchManager;
use App\Models\FinanceEmployee;
use App\Models\Person;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class StaffOperationalManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function createTeacher(
        User $actor,
        Person $person
    ): Teacher {
        /** @var Teacher $record */
        $record = $this->createRecord(
            actor: $actor,
            person: $person,
            modelClass: Teacher::class,
            role: SystemRole::Teacher,
            actionType: 'teacher.created'
        );

        return $record;
    }

    public function createBranchManager(
        User $actor,
        Person $person
    ): BranchManager {
        /** @var BranchManager $record */
        $record = $this->createRecord(
            actor: $actor,
            person: $person,
            modelClass: BranchManager::class,
            role: SystemRole::BranchManager,
            actionType: 'branch_manager.created'
        );

        return $record;
    }

    public function createFinanceEmployee(
        User $actor,
        Person $person
    ): FinanceEmployee {
        /** @var FinanceEmployee $record */
        $record = $this->createRecord(
            actor: $actor,
            person: $person,
            modelClass: FinanceEmployee::class,
            role: SystemRole::FinanceEmployee,
            actionType: 'finance_employee.created'
        );

        return $record;
    }

    public function linkTeacherAccount(
        User $actor,
        Teacher $teacher,
        User $account
    ): Teacher {
        /** @var Teacher $record */
        $record = $this->linkAccount(
            actor: $actor,
            record: $teacher,
            account: $account,
            modelClass: Teacher::class,
            expectedRole: SystemRole::Teacher,
            actionType: 'teacher.account_linked'
        );

        return $record;
    }

    public function linkBranchManagerAccount(
        User $actor,
        BranchManager $branchManager,
        User $account
    ): BranchManager {
        /** @var BranchManager $record */
        $record = $this->linkAccount(
            actor: $actor,
            record: $branchManager,
            account: $account,
            modelClass: BranchManager::class,
            expectedRole: SystemRole::BranchManager,
            actionType: 'branch_manager.account_linked'
        );

        return $record;
    }

    public function linkFinanceEmployeeAccount(
        User $actor,
        FinanceEmployee $financeEmployee,
        User $account
    ): FinanceEmployee {
        /** @var FinanceEmployee $record */
        $record = $this->linkAccount(
            actor: $actor,
            record: $financeEmployee,
            account: $account,
            modelClass: FinanceEmployee::class,
            expectedRole: SystemRole::FinanceEmployee,
            actionType: 'finance_employee.account_linked'
        );

        return $record;
    }

    /**
     * @param class-string<Teacher|BranchManager|FinanceEmployee> $modelClass
     */
    private function createRecord(
        User $actor,
        Person $person,
        string $modelClass,
        SystemRole $role,
        string $actionType
    ): Model {
        return DB::transaction(
            function () use (
                $actor,
                $person,
                $modelClass,
                $role,
                $actionType
            ): Model {
                [
                    $actor,
                    $centerId,
                ] = $this->authorizedActor(
                    $actor
                );

                $person =
                    $this->lockPersistedPerson(
                        $person,
                        $centerId
                    );

                /*
                 * Active and deactivated records both represent
                 * the same operational identity.
                 *
                 * A deactivated record must eventually be
                 * reactivated, never duplicated.
                 */
                $existing =
                    $modelClass::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'person_id',
                        $person->id
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw new DomainException(
                        sprintf(
                            'This Person already has a %s operational record in the language center.',
                            $role->label()
                        )
                    );
                }

                $record =
                    $modelClass::withoutGlobalScopes()
                    ->create([
                        'center_id' =>
                        $centerId,

                        'person_id' =>
                        $person->id,

                        'user_id' =>
                        null,

                        'status' =>
                        StaffStatus::Active,

                        'deactivated_at' =>
                        null,
                    ])
                    ->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: $actionType,
                    subject: $record,
                    afterValues: $this->staffAuditValues(
                        $record
                    )
                );

                return $record;
            },
            3
        );
    }

    /**
     * @param Teacher|BranchManager|FinanceEmployee $record
     * @param class-string<Teacher|BranchManager|FinanceEmployee> $modelClass
     */
    private function linkAccount(
        User $actor,
        Model $record,
        User $account,
        string $modelClass,
        SystemRole $expectedRole,
        string $actionType
    ): Model {
        return DB::transaction(
            function () use (
                $actor,
                $record,
                $account,
                $modelClass,
                $expectedRole,
                $actionType
            ): Model {
                [
                    $actor,
                    $centerId,
                ] = $this->authorizedActor(
                    $actor
                );

                $record =
                    $this->lockPersistedStaffRecord(
                        $record,
                        $modelClass,
                        $centerId
                    );

                if (
                    $record->status
                    !== StaffStatus::Active
                ) {
                    throw new DomainException(
                        'A deactivated Staff record must be reactivated before linking a User Account.'
                    );
                }

                /*
                 * Existing linkage is immutable through this
                 * operation.
                 */
                if (
                    $record->user_id !== null
                ) {
                    if (
                        $record->user_id
                        === $account->getKey()
                    ) {
                        return $record;
                    }

                    throw new DomainException(
                        'The Staff record is already linked to another User Account.'
                    );
                }

                $account =
                    $this->lockPersistedAccount(
                        $account,
                        $centerId
                    );

                if (
                    $account->person_id
                    !== $record->person_id
                ) {
                    throw new DomainException(
                        'The User Account and Staff record must belong to the same Person.'
                    );
                }

                if (
                    ! $account->hasSystemRole(
                        $expectedRole
                    )
                ) {
                    throw new DomainException(
                        sprintf(
                            'The User Account must have the %s role.',
                            $expectedRole->label()
                        )
                    );
                }

                if (
                    $account->status
                    === AccountStatus::Deactivated
                ) {
                    throw new DomainException(
                        'A deactivated User Account cannot be linked to an active Staff record.'
                    );
                }

                $beforeValues =
                    $this->staffAuditValues(
                        $record
                    );

                $record->forceFill([
                    'user_id' =>
                    $account->id,
                ])->save();

                $record->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: $actionType,
                    subject: $record,
                    beforeValues: $beforeValues,
                    afterValues: $this->staffAuditValues(
                        $record
                    )
                );

                return $record;
            },
            3
        );
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function authorizedActor(
        User $actor
    ): array {
        $actorId =
            $this->numericModelId(
                $actor,
                'staff-management actor'
            );

        $actor =
            User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $actorId
            )
            ->lockForUpdate()
            ->first();

        if ($actor === null) {
            throw new InvalidArgumentException(
                'The Staff-management actor no longer exists.'
            );
        }

        if (
            $actor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active User Account may manage Staff records.'
            );
        }

        if (
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            throw new AuthorizationException(
                'Only a Center Owner may manage Staff operational records.'
            );
        }

        $center =
            $this->tenant->requireCenter();

        if (
            $actor->center_id
            !== $center->id
            || $actor->person_id === null
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant Center do not match.'
            );
        }

        Gate::forUser($actor)
            ->authorize(
                SystemPermission::ManageStaffAccounts
                    ->value
            );

        return [
            $actor,
            $center->id,
        ];
    }

    private function lockPersistedPerson(
        Person $person,
        int $centerId
    ): Person {
        $personId =
            $this->numericModelId(
                $person,
                'Person'
            );

        $person =
            Person::withoutGlobalScopes()
            ->whereKey(
                $personId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            throw new AuthorizationException(
                'The Person is outside the authorized Center scope.'
            );
        }

        return $person;
    }

    /**
     * @param class-string<Teacher|BranchManager|FinanceEmployee> $modelClass
     */
    private function lockPersistedStaffRecord(
        Model $record,
        string $modelClass,
        int $centerId
    ): Model {
        if (! $record instanceof $modelClass) {
            throw new InvalidArgumentException(
                'The supplied Staff record type does not match the requested operation.'
            );
        }

        $recordId =
            $this->numericModelId(
                $record,
                'Staff record'
            );

        $record =
            $modelClass::withoutGlobalScopes()
            ->whereKey(
                $recordId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            throw new AuthorizationException(
                'The Staff record is outside the authorized Center scope.'
            );
        }

        return $record;
    }

    private function lockPersistedAccount(
        User $account,
        int $centerId
    ): User {
        $accountId =
            $this->numericModelId(
                $account,
                'User Account'
            );

        $account =
            User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $accountId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            throw new AuthorizationException(
                'The User Account is outside the authorized Center scope.'
            );
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function staffAuditValues(
        Model $record
    ): array {
        return [
            'center_id' =>
            $record->getAttribute(
                'center_id'
            ),

            'person_id' =>
            $record->getAttribute(
                'person_id'
            ),

            'user_id' =>
            $record->getAttribute(
                'user_id'
            ),

            'status' =>
            $record->getAttribute(
                'status'
            ),

            'deactivated_at' =>
            $record->getAttribute(
                'deactivated_at'
            ),
        ];
    }

    private function numericModelId(
        Model $model,
        string $label
    ): int {
        if (
            ! $model->exists
            || $model->getKey() === null
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must be persisted.',
                    $label
                )
            );
        }

        $key =
            $model->getKey();

        if (
            ! is_int($key)
            && ! (
                is_string($key)
                && ctype_digit($key)
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must use a numeric identifier.',
                    $label
                )
            );
        }

        return (int) $key;
    }
}
