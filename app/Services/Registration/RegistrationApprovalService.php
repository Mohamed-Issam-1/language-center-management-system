<?php

namespace App\Services\Registration;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Audit\AuditRecorder;
use App\Services\Branches\StaffBranchAssignmentService;
use App\Services\Staff\StaffOperationalManagementService;
use App\Services\Students\StudentManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class RegistrationApprovalService
{
    public function __construct(
        private readonly RegistrationReviewService $review,
        private readonly UserAccountManagementService $accounts,
        private readonly AccountIdentifierGenerator $identifiers,
        private readonly StudentManagementService $students,
        private readonly StaffOperationalManagementService $staff,
        private readonly StaffBranchAssignmentService $staffAssignments,
        private readonly AuditRecorder $audit,
        private readonly BranchContext $branchContext
    ) {}

    public function approve(
        User $actor,
        RegistrationRequest $request
    ): RegistrationApprovalResult {
        return DB::transaction(
            function () use (
                $actor,
                $request
            ): RegistrationApprovalResult {
                $actor =
                    $this->lockPersistedActor(
                        $actor
                    );

                $request =
                    $this->lockPersistedRequest(
                        $request
                    );

                $this->ensurePending(
                    $request
                );

                $role =
                    $this->selectedSystemRole(
                        $request
                    );

                /*
                 * Re-run the existing review authorization
                 * immediately before final approval.
                 *
                 * selectRole() is idempotent when the selected
                 * Role is already correct, so it performs
                 * authorization without producing duplicate
                 * Audit history.
                 */
                $request =
                    $this->review->selectRole(
                        $actor,
                        $request,
                        $role
                    );

                $center =
                    $this->lockPersistedCenter(
                        $request->center_id
                    );

                $branch =
                    $this->resolveApprovalBranch(
                        $actor,
                        $request,
                        $role
                    );

                $identity =
                    $this->validatedIdentity(
                        $request
                    );

                $person =
                    $this->resolveAndSynchronizePerson(
                        $actor,
                        $request,
                        $identity
                    );

                /*
                 * Account identifier allocation must remain
                 * inside this outer transaction.
                 *
                 * If any later approval step fails, the sequence
                 * allocation rolls back with the rest of the
                 * approval workflow.
                 */
                $accountIdentifier =
                    $this->identifiers->generate(
                        $center,
                        $role
                    );

                /*
                 * The plaintext temporary password exists only
                 * in memory long enough to create the account
                 * and later construct the credentials message.
                 *
                 * UserAccountManagementService stores only the
                 * password hash.
                 */
                $temporaryPassword =
                    Str::password(20);

                $account =
                    $this->createRoleState(
                        actor: $actor,
                        center: $center,
                        branch: $branch,
                        person: $person,
                        role: $role,
                        accountIdentifier: $accountIdentifier,
                        recoveryEmail: $identity['email'],
                        temporaryPassword: $temporaryPassword
                    );

                $beforeValues =
                    $this->registrationAuditValues(
                        $request
                    );

                $request->forceFill([
                    'status' =>
                    RegistrationRequestStatus::Approved,

                    'reviewed_by_user_id' =>
                    $actor->id,

                    'reviewed_at' =>
                    now(),

                    'rejection_reason' =>
                    null,

                    'pending_marker' =>
                    null,
                ])->save();

                $request->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'registration_request.approved',
                    subject: $request,
                    beforeValues: $beforeValues,
                    afterValues: $this->registrationAuditValues(
                        $request
                    )
                );

                $person->refresh();

                $account->refresh();

                $account->loadMissing([
                    'role',
                    'person',
                    'center',
                ]);

                return new RegistrationApprovalResult(
                    registrationRequest: $request,
                    person: $person,
                    account: $account,
                    role: $role,
                    temporaryPassword: $temporaryPassword,
                    recipientEmail: $identity['email']
                );
            },
            3
        );
    }

    private function resolveApprovalBranch(
        User $actor,
        RegistrationRequest $request,
        SystemRole $role
    ): ?Branch {
        if (
            ! $this->roleRequiresBranch(
                $role
            )
        ) {
            if (
                $request->selected_branch_id
                !== null
            ) {
                throw new DomainException(
                    sprintf(
                        '%s registration must not have a Branch selected.',
                        $role->label()
                    )
                );
            }

            return null;
        }

        if (
            $request->selected_branch_id
            === null
        ) {
            throw new DomainException(
                sprintf(
                    '%s registration requires a selected Branch before approval.',
                    $role->label()
                )
            );
        }

        $branch =
            Branch::withoutGlobalScopes()
            ->whereKey(
                $request
                    ->selected_branch_id
            )
            ->where(
                'center_id',
                $request->center_id
            )
            ->lockForUpdate()
            ->first();

        if ($branch === null) {
            throw new DomainException(
                'The selected Registration Branch no longer exists in the target Center.'
            );
        }

        /*
         * Re-run persisted Branch and Role authorization.
         *
         * selectBranch() verifies:
         * - same Center,
         * - active Branch,
         * - role requires Branch,
         * - reviewer permissions,
         * - Branch Manager owns the exact Branch.
         */
        $this->review->selectBranch(
            $actor,
            $request,
            $branch
        );

        /*
         * Student services additionally require request-level
         * BranchContext for Branch Manager operations.
         *
         * The Approval service must never invent or change the
         * current request context.
         */
        if (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            if (
                $role
                !== SystemRole::Student
            ) {
                throw new AuthorizationException(
                    'Branch Manager may approve only Student Registration Requests.'
                );
            }

            if (
                ! $this->branchContext
                    ->isBranchScoped()
                || $this->branchContext
                ->branchId()
                !== $branch->id
            ) {
                throw new AuthorizationException(
                    'Branch Manager approval requires the current Branch context to match the selected Student Branch.'
                );
            }
        }

        return $branch;
    }

    /**
     * @param array{
     *     national_id_number: string,
     *     full_name: string,
     *     date_of_birth: string,
     *     city_of_residence: string,
     *     email: string,
     *     phone_number: string,
     *     personal_picture_path: ?string
     * } $identity
     */
    private function resolveAndSynchronizePerson(
        User $actor,
        RegistrationRequest $request,
        array $identity
    ): Person {
        $person =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $request->center_id
            )
            ->where(
                'national_id_number',
                $identity['national_id_number']
            )
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            $person =
                Person::withoutGlobalScopes()
                ->create([
                    'center_id' =>
                    $request->center_id,

                    ...$identity,
                ])
                ->refresh();

            $this->audit->record(
                actor: $actor,
                actionType: 'person.registration_identity_created',
                subject: $person,
                afterValues: $this->personAuditValues(
                    $person
                )
            );

            return $person;
        }

        $beforeValues =
            $this->personAuditValues(
                $person
            );

        $person->forceFill([
            'full_name' =>
            $identity['full_name'],

            'date_of_birth' =>
            $identity['date_of_birth'],

            'city_of_residence' =>
            $identity['city_of_residence'],

            'email' =>
            $identity['email'],

            'phone_number' =>
            $identity['phone_number'],

            'personal_picture_path' =>
            $identity['personal_picture_path'],
        ]);

        /*
         * Re-approval of identical validated identity data
         * should not produce duplicate Person Audit history.
         */
        if (! $person->isDirty()) {
            return $person->refresh();
        }

        $person->save();
        $person->refresh();

        $this->audit->record(
            actor: $actor,
            actionType: 'person.registration_identity_updated',
            subject: $person,
            beforeValues: $beforeValues,
            afterValues: $this->personAuditValues(
                $person
            )
        );

        return $person;
    }

    private function createRoleState(
        User $actor,
        Center $center,
        ?Branch $branch,
        Person $person,
        SystemRole $role,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        return match ($role) {
            SystemRole::CenterOwner =>
            $this->createGenericAccount(
                actor: $actor,
                center: $center,
                person: $person,
                role: $role,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            ),

            SystemRole::Teacher =>
            $this->createTeacherState(
                actor: $actor,
                center: $center,
                person: $person,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            ),

            SystemRole::BranchManager =>
            $this->createBranchManagerState(
                actor: $actor,
                center: $center,
                branch: $this->requireBranch(
                    $branch,
                    $role
                ),
                person: $person,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            ),

            SystemRole::FinanceEmployee =>
            $this->createFinanceEmployeeState(
                actor: $actor,
                center: $center,
                branch: $this->requireBranch(
                    $branch,
                    $role
                ),
                person: $person,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            ),

            SystemRole::Student =>
            $this->createStudentState(
                actor: $actor,
                branch: $this->requireBranch(
                    $branch,
                    $role
                ),
                person: $person,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            ),

            SystemRole::PlatformOwner =>
            throw new LogicException(
                'Platform Owner is not a valid target for Center Registration approval.'
            ),
        };
    }

    private function createGenericAccount(
        User $actor,
        Center $center,
        Person $person,
        SystemRole $role,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        return $this->accounts->create(
            actor: $actor,
            center: $center,
            nationalIdNumber: $person->national_id_number,
            role: $role,
            accountLoginIdentifier: $accountIdentifier,
            recoveryEmail: $recoveryEmail,
            temporaryPassword: $temporaryPassword
        );
    }

    private function createTeacherState(
        User $actor,
        Center $center,
        Person $person,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        $account =
            $this->createGenericAccount(
                actor: $actor,
                center: $center,
                person: $person,
                role: SystemRole::Teacher,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            );

        $teacher =
            $this->staff->createTeacher(
                $actor,
                $person
            );

        $this->staff->linkTeacherAccount(
            $actor,
            $teacher,
            $account
        );

        return $account;
    }

    private function createBranchManagerState(
        User $actor,
        Center $center,
        Branch $branch,
        Person $person,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        $account =
            $this->createGenericAccount(
                actor: $actor,
                center: $center,
                person: $person,
                role: SystemRole::BranchManager,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            );

        $record =
            $this->staff
            ->createBranchManager(
                $actor,
                $person
            );

        $this->staff
            ->linkBranchManagerAccount(
                $actor,
                $record,
                $account
            );

        $this->staffAssignments
            ->assignBranchManager(
                $actor,
                $account,
                $branch
            );

        return $account;
    }

    private function createFinanceEmployeeState(
        User $actor,
        Center $center,
        Branch $branch,
        Person $person,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        $account =
            $this->createGenericAccount(
                actor: $actor,
                center: $center,
                person: $person,
                role: SystemRole::FinanceEmployee,
                accountIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            );

        $record =
            $this->staff
            ->createFinanceEmployee(
                $actor,
                $person
            );

        $this->staff
            ->linkFinanceEmployeeAccount(
                $actor,
                $record,
                $account
            );

        $this->staffAssignments
            ->assignFinanceEmployee(
                $actor,
                $account,
                $branch
            );

        return $account;
    }

    private function createStudentState(
        User $actor,
        Branch $branch,
        Person $person,
        string $accountIdentifier,
        string $recoveryEmail,
        string $temporaryPassword
    ): User {
        /*
         * StudentManagementService reuses the Person through
         * Center + National ID, so the approved full Person
         * identity synchronized earlier remains authoritative.
         */
        $student =
            $this->students->register(
                actor: $actor,
                branch: $branch,
                nationalIdNumber: $person->national_id_number
            );

        return $this->accounts
            ->createStudentAccountForStudent(
                actor: $actor,
                student: $student,
                accountLoginIdentifier: $accountIdentifier,
                recoveryEmail: $recoveryEmail,
                temporaryPassword: $temporaryPassword
            );
    }

    private function requireBranch(
        ?Branch $branch,
        SystemRole $role
    ): Branch {
        if ($branch === null) {
            throw new LogicException(
                sprintf(
                    '%s approval requires a resolved Branch.',
                    $role->label()
                )
            );
        }

        return $branch;
    }

    /**
     * @return array{
     *     national_id_number: string,
     *     full_name: string,
     *     date_of_birth: string,
     *     city_of_residence: string,
     *     email: string,
     *     phone_number: string,
     *     personal_picture_path: ?string
     * }
     */
    private function validatedIdentity(
        RegistrationRequest $request
    ): array {
        $nationalIdNumber =
            trim(
                (string)
                $request->national_id_number
            );

        $fullName =
            trim(
                (string)
                $request->full_name
            );

        $city =
            trim(
                (string)
                $request->city_of_residence
            );

        $email =
            Str::lower(
                trim(
                    (string)
                    $request->email
                )
            );

        $phone =
            trim(
                (string)
                $request->phone_number
            );

        if ($nationalIdNumber === '') {
            throw new DomainException(
                'Registration approval requires a National ID Number.'
            );
        }

        if ($fullName === '') {
            throw new DomainException(
                'Registration approval requires the full name.'
            );
        }

        if ($request->date_of_birth === null) {
            throw new DomainException(
                'Registration approval requires the date of birth.'
            );
        }

        if ($city === '') {
            throw new DomainException(
                'Registration approval requires the city of residence.'
            );
        }

        if (
            $email === ''
            || filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'Registration approval requires a valid email address.'
            );
        }

        if ($phone === '') {
            throw new DomainException(
                'Registration approval requires a phone number.'
            );
        }

        $picture =
            $request
            ->personal_picture_path;

        if (is_string($picture)) {
            $picture = trim(
                $picture
            );

            if ($picture === '') {
                $picture = null;
            }
        }

        return [
            'national_id_number' =>
            $nationalIdNumber,

            'full_name' =>
            $fullName,

            'date_of_birth' =>
            $request
                ->date_of_birth
                ->format('Y-m-d'),

            'city_of_residence' =>
            $city,

            'email' =>
            $email,

            'phone_number' =>
            $phone,

            'personal_picture_path' =>
            $picture,
        ];
    }

    private function selectedSystemRole(
        RegistrationRequest $request
    ): SystemRole {
        if (
            $request->selected_role_id
            === null
        ) {
            throw new DomainException(
                'A System Role must be selected before final Registration approval.'
            );
        }

        $role =
            Role::query()
            ->find(
                $request
                    ->selected_role_id
            );

        if ($role === null) {
            throw new LogicException(
                'The Registration Request references a missing System Role.'
            );
        }

        $systemRole =
            SystemRole::tryFrom(
                $role->code
            );

        if ($systemRole === null) {
            throw new LogicException(
                'The Registration Request references an unknown System Role.'
            );
        }

        if (
            $systemRole
            === SystemRole::PlatformOwner
        ) {
            throw new DomainException(
                'Platform Owner is not a valid Center Registration role.'
            );
        }

        return $systemRole;
    }

    private function roleRequiresBranch(
        SystemRole $role
    ): bool {
        return in_array(
            $role,
            [
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Student,
            ],
            true
        );
    }

    private function ensurePending(
        RegistrationRequest $request
    ): void {
        if (
            $request->status
            !== RegistrationRequestStatus::Pending
        ) {
            throw new DomainException(
                'Only Pending Registration Requests may be approved.'
            );
        }
    }

    private function lockPersistedActor(
        User $actor
    ): User {
        $actorId =
            $this->numericModelId(
                $actor,
                'Registration approval actor'
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
                'The Registration approval actor no longer exists.'
            );
        }

        if (
            $actor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an active User Account may approve Registration Requests.'
            );
        }

        return $actor;
    }

    private function lockPersistedRequest(
        RegistrationRequest $request
    ): RegistrationRequest {
        $requestId =
            $this->numericModelId(
                $request,
                'Registration Request'
            );

        $request =
            RegistrationRequest::withoutGlobalScopes()
            ->whereKey(
                $requestId
            )
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw new InvalidArgumentException(
                'The Registration Request no longer exists.'
            );
        }

        return $request;
    }

    private function lockPersistedCenter(
        int $centerId
    ): Center {
        $center =
            Center::withoutGlobalScopes()
            ->whereKey(
                $centerId
            )
            ->lockForUpdate()
            ->first();

        if ($center === null) {
            throw new LogicException(
                'The Registration Request references a missing language center.'
            );
        }

        return $center;
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationAuditValues(
        RegistrationRequest $request
    ): array {
        return [
            'status' =>
            $request->status->value,

            'selected_role' =>
            $this->selectedSystemRole(
                $request
            )->value,

            'selected_branch_id' =>
            $request
                ->selected_branch_id,

            'reviewed_by_user_id' =>
            $request
                ->reviewed_by_user_id,

            'reviewed_at' =>
            $request->reviewed_at,

            'rejection_reason' =>
            $request
                ->rejection_reason,

            'pending_marker' =>
            $request
                ->pending_marker,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personAuditValues(
        Person $person
    ): array {
        return [
            'center_id' =>
            $person->center_id,

            'national_id_number' =>
            $person
                ->national_id_number,

            'full_name' =>
            $person->full_name,

            'date_of_birth' =>
            $person->date_of_birth,

            'city_of_residence' =>
            $person
                ->city_of_residence,

            'email' =>
            $person->email,

            'phone_number' =>
            $person->phone_number,

            'personal_picture_path' =>
            $person
                ->personal_picture_path,
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
