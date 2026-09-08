<?php

namespace App\Services\Students;

use App\Models\Center;
use App\Models\Person;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class StudentProfileReadService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Return the canonical self-facing Student Profile.
     *
     * Identity information comes from Person.
     *
     * User Account information is intentionally kept separate
     * from Person identity because they represent different
     * domains inside LCMS.
     *
     * @return array{
     *     student: array<string, mixed>,
     *     identity: array<string, mixed>,
     *     account: array<string, mixed>,
     *     profile_management: array<string, string>
     * }
     */
    public function profile(
        User $actor
    ): array {
        $context =
            $this->authorizedStudentContext(
                $actor
            );

        $person =
            $context['student']
            ->person;

        if ($person === null) {
            throw new LogicException(
                'The Student Profile references a missing Person identity.'
            );
        }

        return [
            'student' =>
            $this->studentPayload(
                $context['actor'],
                $context['student'],
                $context['center']
            ),

            'identity' =>
            $this->identityPayload(
                $person
            ),

            'account' =>
            $this->accountPayload(
                $context['actor']
            ),

            /*
             * Keep frontend behavior explicit.
             *
             * Person is shared identity state. LCMS currently
             * allows shared identity changes only through the
             * Center-owned identity-management workflow.
             *
             * Student self-service password changes already
             * use the established password lifecycle.
             */
            'profile_management' => [
                'identity' =>
                'admin_managed',

                'personal_picture' =>
                'admin_managed',

                'recovery_email' =>
                'admin_managed',

                'password' =>
                'self_service',
            ],
        ];
    }

    /**
     * Resolve the exact persisted Active Student account.
     *
     * Never trust mutable in-memory User state supplied by
     * the caller.
     *
     * @return array{
     *     actor: User,
     *     student: Student,
     *     center: Center,
     *     center_id: int
     * }
     */
    private function authorizedStudentContext(
        User $actor
    ): array {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Student Profile reads require a persisted User Account.'
            );
        }

        $persistedActor =
            User::query()
            ->withoutGlobalScopes()
            ->with(
                'role'
            )
            ->whereKey(
                $actor->getKey()
            )
            ->first();

        if ($persistedActor === null) {
            throw new AuthorizationException(
                'The Student User Account could not be resolved.'
            );
        }

        if (
            $persistedActor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an Active User Account may read the Student Profile.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'The account is not authorized for the Student Profile.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Student Profile reads require a Center-scoped tenant context.'
            );
        }

        $center =
            $this->tenant
            ->center();

        $centerId =
            $this->tenant
            ->centerId();

        if (
            $center === null
            || $centerId === null
            || $persistedActor->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'Authenticated Student account and tenant context do not match.'
            );
        }

        if (
            $persistedActor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Student Profile access requires a linked Person identity.'
            );
        }

        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->with([
                'person' =>
                fn($query) =>
                $query->withoutGlobalScopes(),

                'branch' =>
                fn($query) =>
                $query->withoutGlobalScopes(),
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'user_id',
                $persistedActor->id
            )
            ->where(
                'person_id',
                $persistedActor->person_id
            )
            ->where(
                'status',
                StudentStatus::Active->value
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'Student Profile access requires an Active Student record linked to this exact account and Person.'
            );
        }

        return [
            'actor' =>
            $persistedActor,

            'student' =>
            $student,

            'center' =>
            $center,

            'center_id' =>
            (int) $centerId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function studentPayload(
        User $actor,
        Student $student,
        Center $center
    ): array {
        return [
            'id' =>
            (int) $student->id,

            'person_id' =>
            (int) $student->person_id,

            'user_id' =>
            (int) $student->user_id,

            /*
             * LCMS currently has no separate Student Number
             * domain field.
             *
             * Do not invent one. The generated account login
             * identifier remains an Account identifier.
             */
            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            'name' =>
            $student
                ->person
                ?->full_name
                ?? $actor->name,

            'status' =>
            $student
                ->status
                ->value,

            'center' => [
                'id' =>
                (int) $center->id,

                'code' =>
                $center->code,

                'name' =>
                $center->name,
            ],

            'branch' => [
                'id' =>
                (int) $student->branch_id,

                'code' =>
                $student
                    ->branch
                    ?->code,

                'name' =>
                $student
                    ->branch
                    ?->name,
            ],
        ];
    }

    /**
     * Return canonical Person identity.
     *
     * The private storage path of the personal picture is
     * intentionally not exposed to the frontend.
     *
     * @return array<string, mixed>
     */
    private function identityPayload(
        Person $person
    ): array {
        return [
            'person_id' =>
            (int) $person->id,

            'national_id_number' =>
            $person
                ->national_id_number,

            'full_name' =>
            $person
                ->full_name,

            'date_of_birth' =>
            $person
                ->date_of_birth
                ?->format(
                    'Y-m-d'
                ),

            'city_of_residence' =>
            $person
                ->city_of_residence,

            'email' =>
            $person
                ->email,

            'phone_number' =>
            $person
                ->phone_number,

            /*
             * Do not leak the private Storage path.
             *
             * A dedicated authorized picture-delivery endpoint
             * can later turn this state into a safe URL if the
             * frontend requires the actual image.
             */
            'personal_picture_available' =>
            filled(
                $person
                    ->personal_picture_path
            ),
        ];
    }

    /**
     * Return safe Student-owned Account metadata.
     *
     * Password hashes, remember tokens, lockout internals and
     * other authentication secrets are deliberately excluded.
     *
     * @return array<string, mixed>
     */
    private function accountPayload(
        User $actor
    ): array {
        return [
            'user_id' =>
            (int) $actor->id,

            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            'recovery_email' =>
            $actor
                ->recovery_email,

            'status' =>
            $actor
                ->status
                ->value,

            'email_verified_at' =>
            $actor
                ->email_verified_at
                ?->toDateTimeString(),

            'must_change_password' =>
            (bool) $actor
                ->must_change_password,

            'password_changed_at' =>
            $actor
                ->password_changed_at
                ?->toDateTimeString(),

            'last_login_at' =>
            $actor
                ->last_login_at
                ?->toDateTimeString(),
        ];
    }
}
