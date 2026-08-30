<?php

namespace App\Services\Registration;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Audit\AuditRecorder;
use App\Services\Students\StudentManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\StudentStatus;
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
        private readonly StudentManagementService $students,
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
                $actor = $this->lockPersistedActor(
                    $actor
                );

                $request = $this->lockPersistedRequest(
                    $request
                );

                $this->ensurePending(
                    $request
                );

                /*
                 * Public self-registration is a Student-only workflow.
                 * The role is assigned during submission and must not
                 * be changed during approval.
                 */
                $role = $this->selectedSystemRole(
                    $request
                );

                /*
                 * Re-run the existing review authorization immediately
                 * before approval. Because the Student role is already
                 * selected, this is authorization-only and idempotent.
                 */
                $request = $this->review->selectRole(
                    $actor,
                    $request,
                    $role
                );

                $center = $this->lockPersistedCenter(
                    $request->center_id
                );

                if ($center->status !== CenterStatus::Active) {
                    throw new DomainException(
                        'A self-registration request may be approved only while its language center is active.'
                    );
                }

                $branch = $this->resolveApprovalBranch(
                    $actor,
                    $request
                );

                $identity = $this->validatedIdentity(
                    $request
                );

                /*
                 * The SRS requires Person and the Pending Student User
                 * to exist from the original self-registration.
                 * Approval therefore reuses those records instead of
                 * creating replacements.
                 */
                $person = $this->resolveAndSynchronizePerson(
                    $actor,
                    $request,
                    $identity
                );

                $account = $this->resolvePendingAccount(
                    $request,
                    $person
                );

                /*
                 * Reuse a matching Student when one already exists.
                 * Otherwise create it. The Student is linked before
                 * account activation so a Branch Manager's account
                 * management authorization can prove branch ownership.
                 */
                $student = $this->resolveStudent(
                    $actor,
                    $branch,
                    $person
                );

                $student = $this->students->linkAccount(
                    $actor,
                    $student,
                    $account
                );

                $account = $this->accounts->activate(
                    $actor,
                    $account
                );

                $beforeValues = $this->registrationAuditValues(
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

                /*
                 * RegistrationApprovalResult still contains the old
                 * temporary-password fields. Self-registration no
                 * longer creates or sends a temporary password, so an
                 * empty compatibility value is returned for now.
                 *
                 * The result object / Filament credential-delivery
                 * action should be cleaned up in the next checkpoint.
                 */
                return new RegistrationApprovalResult(
                    registrationRequest: $request,
                    person: $person,
                    account: $account,
                    role: SystemRole::Student,
                    temporaryPassword: '',
                    recipientEmail: $identity['email']
                );
            },
            3
        );
    }

    private function resolveApprovalBranch(
        User $actor,
        RegistrationRequest $request
    ): Branch {
        if ($request->selected_branch_id === null) {
            throw new DomainException(
                'Student self-registration requires a selected Branch before approval.'
            );
        }

        $branch = Branch::withoutGlobalScopes()
            ->whereKey(
                $request->selected_branch_id
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
         * selectBranch() re-checks:
         * - same Center,
         * - active Branch,
         * - reviewer permissions,
         * - Branch Manager ownership.
         */
        $this->review->selectBranch(
            $actor,
            $request,
            $branch
        );

        if (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            if (
                ! $this->branchContext->isBranchScoped()
                || $this->branchContext->branchId()
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
        if ($request->person_id === null) {
            throw new DomainException(
                'Self-registration approval requires the Person created during submission.'
            );
        }

        $person = Person::withoutGlobalScopes()
            ->whereKey(
                $request->person_id
            )
            ->where(
                'center_id',
                $request->center_id
            )
            ->lockForUpdate()
            ->first();

        if ($person === null) {
            throw new DomainException(
                'The Person linked to this self-registration request no longer exists in the target Center.'
            );
        }

        if (
            trim((string) $person->national_id_number)
            !== $identity['national_id_number']
        ) {
            throw new DomainException(
                'The self-registration Person does not match the request National ID.'
            );
        }

        $beforeValues = $this->personAuditValues(
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

    private function resolvePendingAccount(
        RegistrationRequest $request,
        Person $person
    ): User {
        if ($request->user_id === null) {
            throw new DomainException(
                'Self-registration approval requires the Pending User Account created during submission.'
            );
        }

        $account = User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $request->user_id
            )
            ->where(
                'center_id',
                $request->center_id
            )
            ->where(
                'person_id',
                $person->id
            )
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            throw new DomainException(
                'The Pending User Account linked to this self-registration request no longer exists or no longer matches its Person and Center.'
            );
        }

        if (
            $account->systemRole()
            !== SystemRole::Student
        ) {
            throw new DomainException(
                'A self-registration request must be linked to a Student User Account.'
            );
        }

        if (
            $account->status
            !== AccountStatus::Pending
        ) {
            throw new DomainException(
                'Only the Pending User Account created for this self-registration request may be activated by approval.'
            );
        }

        if (
            trim(
                (string)
                $account->account_login_identifier
            ) === ''
        ) {
            throw new DomainException(
                'The Pending Student User Account does not have a login identifier.'
            );
        }

        if ($account->must_change_password) {
            throw new DomainException(
                'The self-registered Student Account must retain the applicant-selected password.'
            );
        }

        return $account;
    }

    private function resolveStudent(
        User $actor,
        Branch $branch,
        Person $person
    ): Student {
        $student = Student::withoutGlobalScopes()
            ->where(
                'center_id',
                $person->center_id
            )
            ->where(
                'person_id',
                $person->id
            )
            ->lockForUpdate()
            ->first();

        if ($student === null) {
            return $this->students->register(
                actor: $actor,
                branch: $branch,
                nationalIdNumber: $person->national_id_number
            );
        }

        if (
            $student->status
            === StudentStatus::Archived
        ) {
            $student = $this->students->restore(
                $actor,
                $student
            );
        }

        if (
            $student->branch_id
            !== $branch->id
        ) {
            $student = $this->students->update(
                $actor,
                $student,
                [
                    'branch_id' =>
                    $branch->id,
                ]
            );
        }

        return $student;
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
        $nationalIdNumber = trim(
            (string)
            $request->national_id_number
        );

        $fullName = trim(
            (string)
            $request->full_name
        );

        $city = trim(
            (string)
            $request->city_of_residence
        );

        $email = Str::lower(
            trim(
                (string)
                $request->email
            )
        );

        $phone = trim(
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

        $picture = $request
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
        if ($request->selected_role_id === null) {
            throw new DomainException(
                'A self-registration request must already have the Student role assigned.'
            );
        }

        $role = Role::query()
            ->find(
                $request->selected_role_id
            );

        if ($role === null) {
            throw new LogicException(
                'The Registration Request references a missing System Role.'
            );
        }

        $systemRole = SystemRole::tryFrom(
            $role->code
        );

        if ($systemRole === null) {
            throw new LogicException(
                'The Registration Request references an unknown System Role.'
            );
        }

        if (
            $systemRole
            !== SystemRole::Student
        ) {
            throw new DomainException(
                'Public self-registration approval supports the Student role only.'
            );
        }

        return SystemRole::Student;
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
        $actorId = $this->numericModelId(
            $actor,
            'Registration approval actor'
        );

        $actor = User::withoutGlobalScopes()
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
        $requestId = $this->numericModelId(
            $request,
            'Registration Request'
        );

        $request = RegistrationRequest::withoutGlobalScopes()
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
        $center = Center::withoutGlobalScopes()
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
            $request->selected_branch_id,

            'person_id' =>
            $request->person_id,

            'user_id' =>
            $request->user_id,

            'reviewed_by_user_id' =>
            $request->reviewed_by_user_id,

            'reviewed_at' =>
            $request->reviewed_at,

            'rejection_reason' =>
            $request->rejection_reason,

            'pending_marker' =>
            $request->pending_marker,
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
            $person->national_id_number,

            'full_name' =>
            $person->full_name,

            'date_of_birth' =>
            $person->date_of_birth,

            'city_of_residence' =>
            $person->city_of_residence,

            'email' =>
            $person->email,

            'phone_number' =>
            $person->phone_number,

            'personal_picture_path' =>
            $person->personal_picture_path,
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

        $key = $model->getKey();

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
