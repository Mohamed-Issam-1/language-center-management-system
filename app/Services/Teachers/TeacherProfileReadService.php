<?php

namespace App\Services\Teachers;

use App\Models\Center;
use App\Models\Person;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class TeacherProfileReadService
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    /**
     * Return the canonical self-facing Teacher Profile.
     *
     * Person owns canonical human identity.
     *
     * User owns authentication/account metadata.
     *
     * Teacher represents the operational Teacher record and does
     * not currently contain a separate specialization,
     * qualification, employee number, or similar profile domain.
     *
     * @return array{
     *     teacher: array<string, mixed>,
     *     identity: array<string, mixed>,
     *     account: array<string, mixed>,
     *     profile_management: array<string, string>
     * }
     */
    public function profile(
        User $actor
    ): array {
        $context =
            $this->authorizedTeacherContext(
                $actor
            );

        $person =
            $context['teacher']
            ->person;

        if ($person === null) {
            throw new LogicException(
                'The Teacher Profile references a missing Person identity.'
            );
        }

        return [
            'teacher' =>
            $this->teacherPayload(
                $context['actor'],
                $context['teacher'],
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
             * Person identity remains Center-managed.
             *
             * The existing generic ProfileController modifies
             * legacy User name/email fields and is not the
             * authoritative Person identity-management workflow.
             *
             * Recovery email is also account-administrator
             * managed in the current LCMS domain.
             *
             * Password changes continue through the established
             * authenticated self-service password lifecycle.
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
     * Resolve the exact persisted Active Teacher account.
     *
     * Do not trust mutable or stale in-memory authentication
     * state supplied by a caller.
     *
     * @return array{
     *     actor: User,
     *     teacher: Teacher,
     *     center: Center,
     *     center_id: int
     * }
     */
    private function authorizedTeacherContext(
        User $actor
    ): array {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new AuthorizationException(
                'Teacher Profile reads require a persisted User Account.'
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
                'The Teacher User Account could not be resolved.'
            );
        }

        if (
            $persistedActor->status
            !== AccountStatus::Active
        ) {
            throw new AuthorizationException(
                'Only an Active User Account may read the Teacher Profile.'
            );
        }

        if (
            $persistedActor->systemRole()
            !== SystemRole::Teacher
        ) {
            throw new AuthorizationException(
                'The account is not authorized for the Teacher Profile.'
            );
        }

        if (
            ! $this->tenant
                ->isCenterScoped()
        ) {
            throw new AuthorizationException(
                'Teacher Profile reads require a Center-scoped tenant context.'
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
                'Authenticated Teacher account and tenant context do not match.'
            );
        }

        if (
            $persistedActor->person_id
            === null
        ) {
            throw new AuthorizationException(
                'Teacher Profile access requires a linked Person identity.'
            );
        }

        $teacher =
            Teacher::query()
            ->withoutGlobalScopes()
            ->with([
                'person' =>
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
                StaffStatus::Active->value
            )
            ->first();

        if ($teacher === null) {
            throw new AuthorizationException(
                'Teacher Profile access requires an Active Teacher record linked to this exact account and Person.'
            );
        }

        return [
            'actor' =>
            $persistedActor,

            'teacher' =>
            $teacher,

            'center' =>
            $center,

            'center_id' =>
            (int) $centerId,
        ];
    }

    /**
     * Return the operational Teacher identity.
     *
     * @return array<string, mixed>
     */
    private function teacherPayload(
        User $actor,
        Teacher $teacher,
        Center $center
    ): array {
        return [
            'id' =>
            (int) $teacher->id,

            'person_id' =>
            (int) $teacher->person_id,

            'user_id' =>
            (int) $teacher->user_id,

            /*
             * LCMS has no independent Teacher Number field.
             *
             * Do not reinterpret the generated account login
             * identifier as a separate staff/teacher identifier.
             */
            'account_login_identifier' =>
            $actor
                ->account_login_identifier,

            /*
             * Canonical name comes from Person.
             *
             * User.name remains legacy/non-authoritative identity
             * metadata.
             */
            'name' =>
            $teacher
                ->person
                ?->full_name
                ?? $actor->name,

            'status' =>
            $teacher
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
        ];
    }

    /**
     * Return canonical Person identity.
     *
     * The raw private personal_picture_path is deliberately not
     * returned.
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
             * Do not expose the private Storage path.
             *
             * A dedicated authorized picture-delivery endpoint
             * may later translate availability into a safe URL.
             */
            'personal_picture_available' =>
            filled(
                $person
                    ->personal_picture_path
            ),
        ];
    }

    /**
     * Return safe Teacher-owned Account metadata.
     *
     * Authentication secrets and lockout internals remain
     * intentionally excluded.
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