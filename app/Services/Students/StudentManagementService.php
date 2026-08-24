<?php

namespace App\Services\Students;

use App\Models\Branch;
use App\Models\Person;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StudentManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly BranchContext $branchContext,
        private readonly AuditRecorder $audit
    ) {}

    public function register(
        User $actor,
        Branch $branch,
        string $nationalIdNumber
    ): Student {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $branch,
                $nationalIdNumber,
                $centerId
            ): Student {
                $branch = $this->lockPersistedBranch(
                    $branch,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            Student::class,
                            $branch,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $branch->id
                );

                if (
                    $branch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Student may be registered only in an active Branch.'
                    );
                }

                $nationalIdNumber = trim(
                    $nationalIdNumber
                );

                if ($nationalIdNumber === '') {
                    throw new DomainException(
                        'A National ID Number is required.'
                    );
                }

                /*
                 * Person is the shared identity record.
                 *
                 * Reuse the matching Person inside the Center
                 * rather than duplicating identity information
                 * inside Student.
                 */
                $person = Person::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
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
                            'center_id' => $centerId,
                            'national_id_number' =>
                            $nationalIdNumber,
                        ]);
                }

                /*
                 * Active and Archived Student records both count
                 * as the same Student identity.
                 *
                 * An Archived Student must be restored rather than
                 * duplicated.
                 */
                $existingStudent =
                    Student::withoutGlobalScopes()
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

                if ($existingStudent) {
                    throw new DomainException(
                        'This Person already has a Student record in the language center.'
                    );
                }

                $student = Student::withoutGlobalScopes()
                    ->create([
                        'center_id' => $centerId,
                        'branch_id' => $branch->id,
                        'person_id' => $person->id,
                        'user_id' => null,
                        'status' => StudentStatus::Active,
                        'archived_at' => null,
                    ])
                    ->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.created',
                    subject: $student,
                    afterValues: $this->studentAuditValues(
                        $student
                    )
                );

                return $student;
            },
            3
        );
    }

    public function update(
        User $actor,
        Student $student,
        array $attributes
    ): Student {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $attributes,
                $centerId
            ): Student {
                $student = $this->lockPersistedStudent(
                    $student,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $student
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $student->branch_id
                );

                if (
                    $student->status
                    === StudentStatus::Archived
                ) {
                    throw new DomainException(
                        'An Archived Student must be restored before being updated.'
                    );
                }

                /*
                 * Student currently has no mutable personal-data
                 * columns of its own.
                 *
                 * Shared identity data remains on Person.
                 * Lifecycle and User Account linkage use explicit
                 * operations.
                 *
                 * General Student update therefore currently owns
                 * only Branch reassignment.
                 */
                $data = Arr::only(
                    $attributes,
                    [
                        'branch_id',
                    ]
                );

                if (
                    ! array_key_exists(
                        'branch_id',
                        $data
                    )
                ) {
                    return $student;
                }

                $targetBranchId =
                    $this->numericIdentifier(
                        $data['branch_id'],
                        'A valid Branch identifier is required.'
                    );

                if (
                    $targetBranchId
                    === $student->branch_id
                ) {
                    return $student;
                }

                $targetBranch =
                    $this->lockPersistedBranchById(
                        $targetBranchId,
                        $centerId
                    );

                /*
                 * Authorization must hold for both:
                 *
                 * 1. the Student's persisted current Branch; and
                 * 2. the requested destination Branch.
                 *
                 * This prevents a Branch Manager from moving a
                 * Student into another Branch.
                 */
                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        [
                            Student::class,
                            $targetBranch,
                        ]
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $targetBranch->id
                );

                if (
                    $targetBranch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Student may be moved only to an active Branch.'
                    );
                }

                $beforeValues =
                    $this->studentAuditValues(
                        $student
                    );

                $student->branch_id =
                    $targetBranch->id;

                $student->save();
                $student->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.updated',
                    subject: $student,
                    beforeValues: $beforeValues,
                    afterValues: $this->studentAuditValues(
                        $student
                    )
                );

                return $student;
            },
            3
        );
    }

    public function archive(
        User $actor,
        Student $student
    ): Student {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $centerId
            ): Student {
                $student = $this->lockPersistedStudent(
                    $student,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'archive',
                        $student
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $student->branch_id
                );

                if (
                    $student->status
                    === StudentStatus::Archived
                ) {
                    return $student;
                }

                $beforeValues =
                    $this->studentAuditValues(
                        $student
                    );

                /*
                 * Student records are retained.
                 *
                 * Archiving changes lifecycle state without
                 * deleting Person, User Account, Student, or any
                 * historical relationships.
                 */
                $student->forceFill([
                    'status' =>
                    StudentStatus::Archived,
                    'archived_at' => now(),
                ])->save();

                $student->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.archived',
                    subject: $student,
                    beforeValues: $beforeValues,
                    afterValues: $this->studentAuditValues(
                        $student
                    )
                );

                return $student;
            },
            3
        );
    }

    public function restore(
        User $actor,
        Student $student
    ): Student {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $centerId
            ): Student {
                $student = $this->lockPersistedStudent(
                    $student,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'restore',
                        $student
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $student->branch_id
                );

                if (
                    $student->status
                    === StudentStatus::Active
                ) {
                    return $student;
                }

                /*
                 * The Student cannot return to active operational
                 * use while its Branch is deactivated.
                 */
                $branch =
                    $this->lockPersistedBranchById(
                        $student->branch_id,
                        $centerId
                    );

                if (
                    $branch->status
                    !== BranchStatus::Active
                ) {
                    throw new DomainException(
                        'A Student cannot be restored while the assigned Branch is deactivated.'
                    );
                }

                $beforeValues =
                    $this->studentAuditValues(
                        $student
                    );

                $student->forceFill([
                    'status' =>
                    StudentStatus::Active,
                    'archived_at' => null,
                ])->save();

                $student->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.restored',
                    subject: $student,
                    beforeValues: $beforeValues,
                    afterValues: $this->studentAuditValues(
                        $student
                    )
                );

                return $student;
            },
            3
        );
    }

    public function linkAccount(
        User $actor,
        Student $student,
        User $account
    ): Student {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $account,
                $centerId
            ): Student {
                $student = $this->lockPersistedStudent(
                    $student,
                    $centerId
                );

                Gate::forUser($actor)
                    ->authorize(
                        'linkAccount',
                        $student
                    );

                $this->ensureOperationalBranchScope(
                    $actor,
                    $student->branch_id
                );

                if (
                    $student->status
                    !== StudentStatus::Active
                ) {
                    throw new DomainException(
                        'An Archived Student must be restored before linking a User Account.'
                    );
                }

                $account =
                    $this->lockPersistedAccount(
                        $account
                    );

                if (
                    $account->systemRole()
                    !== SystemRole::Student
                ) {
                    throw new DomainException(
                        'Only a Student-role User Account may be linked to a Student record.'
                    );
                }

                if (
                    $account->center_id
                    !== $student->center_id
                ) {
                    throw new DomainException(
                        'The User Account and Student record must belong to the same language center.'
                    );
                }

                if (
                    $account->person_id === null
                    || $account->person_id
                    !== $student->person_id
                ) {
                    throw new DomainException(
                        'The User Account and Student record must belong to the same Person.'
                    );
                }

                if (
                    $student->user_id
                    === $account->id
                ) {
                    return $student;
                }

                if ($student->user_id !== null) {
                    throw new DomainException(
                        'The Student record is already linked to another User Account.'
                    );
                }

                $alreadyLinked =
                    Student::withoutGlobalScopes()
                    ->where(
                        'user_id',
                        $account->id
                    )
                    ->whereKeyNot(
                        $student->id
                    )
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyLinked) {
                    throw new DomainException(
                        'The User Account is already linked to another Student record.'
                    );
                }

                $beforeValues =
                    $this->studentAuditValues(
                        $student
                    );

                $student->user_id =
                    $account->id;

                $student->save();
                $student->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'student.account_linked',
                    subject: $student,
                    beforeValues: $beforeValues,
                    afterValues: $this->studentAuditValues(
                        $student
                    )
                );

                return $student;
            },
            3
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center = $this->tenant
            ->requireCenter();

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

    private function ensureOperationalBranchScope(
        User $actor,
        int $branchId
    ): void {
        /*
         * Center Owner is center-wide by role and may operate
         * across Branches inside the current Center.
         */
        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return;
        }

        /*
         * Branch Manager operations must agree with both the
         * authorization Policy and the request's BranchContext.
         *
         * Policy validates the active persisted assignment.
         * BranchContext prevents a branch-scoped request from
         * operating on another Branch.
         */
        if (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            if (
                ! $this->branchContext
                    ->isBranchScoped()
                || $this->branchContext
                ->branchId()
                !== $branchId
            ) {
                throw new AuthorizationException(
                    'The Student operation is outside the current Branch scope.'
                );
            }

            return;
        }

        /*
         * Other roles should already have been rejected by the
         * Policy. Keeping this method fail-closed protects future
         * callers if authorization rules change.
         */
        throw new AuthorizationException(
            'The authenticated account cannot manage Student records.'
        );
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

    private function lockPersistedBranch(
        Branch $branch,
        int $centerId
    ): Branch {
        return $this->lockPersistedBranchById(
            (int) $branch->getKey(),
            $centerId
        );
    }

    private function lockPersistedBranchById(
        int $branchId,
        int $centerId
    ): Branch {
        $branch = Branch::withoutGlobalScopes()
            ->whereKey(
                $branchId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if ($branch->center_id !== $centerId) {
            throw new AuthorizationException(
                'The Branch is outside the authorized Center scope.'
            );
        }

        return $branch;
    }

    private function lockPersistedAccount(
        User $account
    ): User {
        return User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $account->getKey()
            )
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function numericIdentifier(
        mixed $value,
        string $errorMessage
    ): int {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new DomainException(
            $errorMessage
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function studentAuditValues(
        Student $student
    ): array {
        return [
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

            'archived_at' =>
            $student->archived_at,
        ];
    }
}
