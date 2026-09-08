<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Staff\StaffOperationalManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StaffOperationalLifecycleServiceTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_deactivate_and_reactivate_teacher(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::Teacher
            );

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $deactivated =
            $this->service()
            ->deactivateTeacher(
                $actor,
                $teacher
            );

        $this->assertSame(
            StaffStatus::Deactivated,
            $deactivated->status
        );

        $this->assertNotNull(
            $deactivated
                ->deactivated_at
        );

        $activated =
            $this->service()
            ->activateTeacher(
                $actor,
                $teacher
            );

        $this->assertSame(
            StaffStatus::Active,
            $activated->status
        );

        $this->assertNull(
            $activated
                ->deactivated_at
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'teacher.deactivated',

                'subject_id' =>
                $teacher->id,
            ]
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'teacher.activated',

                'subject_id' =>
                $teacher->id,
            ]
        );
    }

    public function test_lifecycle_operations_are_idempotent(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->create();

        $this->service()
            ->deactivateTeacher(
                $actor,
                $teacher
            );

        $auditCount =
            \App\Models\AuditRecord::query()
            ->count();

        $this->service()
            ->deactivateTeacher(
                $actor,
                $teacher
            );

        $this->assertSame(
            $auditCount,
            \App\Models\AuditRecord::query()
                ->count()
        );

        $this->service()
            ->activateTeacher(
                $actor,
                $teacher
            );

        $auditCount =
            \App\Models\AuditRecord::query()
            ->count();

        $this->service()
            ->activateTeacher(
                $actor,
                $teacher
            );

        $this->assertSame(
            $auditCount,
            \App\Models\AuditRecord::query()
                ->count()
        );
    }

    public function test_branch_manager_deactivation_ends_active_assignment(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::BranchManager
            );

        $record =
            BranchManager::factory()
            ->forPerson(
                $person
            )
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $assignment =
            BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $account->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this->service()
            ->deactivateBranchManager(
                $actor,
                $record
            );

        $record->refresh();
        $assignment->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $record->status
        );

        $this->assertNull(
            $assignment
                ->active_marker
        );

        $this->assertNotNull(
            $assignment
                ->ended_at
        );
    }

    public function test_finance_employee_deactivation_ends_active_assignment(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::FinanceEmployee
            );

        $record =
            FinanceEmployee::factory()
            ->forPerson(
                $person
            )
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $assignment =
            FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $account->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $this->service()
            ->deactivateFinanceEmployee(
                $actor,
                $record
            );

        $record->refresh();
        $assignment->refresh();

        $this->assertSame(
            StaffStatus::Deactivated,
            $record->status
        );

        $this->assertNull(
            $assignment
                ->active_marker
        );

        $this->assertNotNull(
            $assignment
                ->ended_at
        );
    }

    public function test_reactivation_does_not_recreate_branch_assignment(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::BranchManager
            );

        $record =
            BranchManager::factory()
            ->forPerson(
                $person
            )
            ->deactivated()
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->service()
            ->activateBranchManager(
                $actor,
                $record
            );

        $record->refresh();

        $this->assertSame(
            StaffStatus::Active,
            $record->status
        );

        $this->assertDatabaseCount(
            'branch_manager_assignments',
            0
        );
    }

    public function test_staff_record_cannot_be_reactivated_while_linked_account_is_deactivated(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::Teacher,
                AccountStatus::Deactivated
            );

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->deactivated()
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activateTeacher(
                $actor,
                $teacher
            );
    }

    public function test_non_center_owner_cannot_change_staff_lifecycle(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->create();

        $actorPerson =
            Person::factory()
            ->for($center)
            ->create();

        $actor =
            $this->roleAccount(
                $center,
                $actorPerson,
                SystemRole::BranchManager
            );

        $this->establishCenter(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->deactivateTeacher(
                $actor,
                $teacher
            );
    }

    public function test_cross_center_staff_lifecycle_is_rejected(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $centerA
            );

        $person =
            Person::factory()
            ->for($centerB)
            ->create();

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->create();

        $this->establishCenter(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->deactivateTeacher(
                $actor,
                $teacher
            );
    }

    public function test_staff_lifecycle_rolls_back_when_audit_recording_fails(): void
    {
        [
            $center,
            $actor,
            $person,
        ] = $this->context();

        $teacher =
            Teacher::factory()
            ->forPerson(
                $person
            )
            ->create();

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive(
                        'record'
                    )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->deactivateTeacher(
                    $actor,
                    $teacher
                );

            $this->fail(
                'Expected Audit failure was not thrown.'
            );
        } catch (
            RuntimeException $exception
        ) {
            $this->assertSame(
                'Audit failure.',
                $exception
                    ->getMessage()
            );
        }

        $teacher->refresh();

        $this->assertSame(
            StaffStatus::Active,
            $teacher->status
        );

        $this->assertNull(
            $teacher
                ->deactivated_at
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:User,
     *     2:Person
     * }
     */
    private function context(): array
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->centerOwner(
                $center
            );

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        return [
            $center,
            $actor,
            $person,
        ];
    }

    private function service(): StaffOperationalManagementService
    {
        return app(
            StaffOperationalManagementService::class
        );
    }

    private function establishCenter(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function centerOwner(
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for($center)
            ->create();

        return $this->roleAccount(
            $center,
            $person,
            SystemRole::CenterOwner
        );
    }

    private function roleAccount(
        Center $center,
        Person $person,
        SystemRole $role,
        AccountStatus $status =
        AccountStatus::Active
    ): User {
        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    $role
                )->id,

                'status' =>
                $status,

                'deactivated_at' =>
                $status
                    === AccountStatus::Deactivated
                    ? now()
                    : null,

                'must_change_password' =>
                false,
            ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}