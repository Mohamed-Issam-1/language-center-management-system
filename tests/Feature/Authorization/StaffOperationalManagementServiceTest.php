<?php

namespace Tests\Feature\Authorization;

use App\Models\BranchManager;
use App\Models\Center;
use App\Models\FinanceEmployee;
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

class StaffOperationalManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_teacher_record(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $teacher = $this->service()
            ->createTeacher(
                $actor,
                $person
            );

        $this->assertSame(
            $center->id,
            $teacher->center_id
        );

        $this->assertSame(
            $person->id,
            $teacher->person_id
        );

        $this->assertNull(
            $teacher->user_id
        );

        $this->assertSame(
            StaffStatus::Active,
            $teacher->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'teacher.created',

                'subject_type' =>
                'teachers',

                'subject_id' =>
                $teacher->id,
            ]
        );
    }

    public function test_center_owner_can_create_branch_manager_record(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $record = $this->service()
            ->createBranchManager(
                $actor,
                $person
            );

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            $person->id,
            $record->person_id
        );

        $this->assertNull(
            $record->user_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'branch_manager.created',

                'subject_id' =>
                $record->id,
            ]
        );
    }

    public function test_center_owner_can_create_finance_employee_record(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $record = $this->service()
            ->createFinanceEmployee(
                $actor,
                $person
            );

        $this->assertSame(
            $person->id,
            $record->person_id
        );

        $this->assertSame(
            StaffStatus::Active,
            $record->status
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'finance_employee.created',

                'subject_id' =>
                $record->id,
            ]
        );
    }

    public function test_duplicate_operational_record_is_rejected(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->service()
            ->createTeacher(
                $actor,
                $person
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createTeacher(
                $actor,
                $person
            );
    }

    public function test_person_from_another_center_cannot_receive_staff_record(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $centerA
        );

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $this->establishCenter(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createTeacher(
                $actor,
                $personB
            );
    }

    public function test_non_center_owner_cannot_create_staff_record(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->roleAccount(
            $center,
            Person::factory()
                ->for($center)
                ->create(),
            SystemRole::BranchManager
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createTeacher(
                $actor,
                $person
            );
    }

    public function test_deactivated_center_owner_cannot_manage_staff_records(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $actor->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createTeacher(
                $actor,
                $person
            );
    }

    public function test_teacher_record_can_link_only_teacher_account_for_same_person(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $center
        );

        $teacher = $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );

        $this->assertSame(
            $account->id,
            $teacher->user_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'teacher.account_linked',

                'subject_id' =>
                $teacher->id,
            ]
        );
    }

    public function test_branch_manager_record_can_link_branch_manager_account(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $record = BranchManager::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::BranchManager
        );

        $this->establishCenter(
            $center
        );

        $record = $this->service()
            ->linkBranchManagerAccount(
                $actor,
                $record,
                $account
            );

        $this->assertSame(
            $account->id,
            $record->user_id
        );
    }

    public function test_finance_employee_record_can_link_finance_account(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $record = FinanceEmployee::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::FinanceEmployee
        );

        $this->establishCenter(
            $center
        );

        $record = $this->service()
            ->linkFinanceEmployeeAccount(
                $actor,
                $record,
                $account
            );

        $this->assertSame(
            $account->id,
            $record->user_id
        );
    }

    public function test_wrong_role_account_cannot_be_linked(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $wrongAccount =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::BranchManager
            );

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $wrongAccount
            );
    }

    public function test_account_for_different_person_cannot_be_linked(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $personA = Person::factory()
            ->for($center)
            ->create();

        $personB = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($personA)
            ->create();

        $accountB = $this->roleAccount(
            $center,
            $personB,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $accountB
            );
    }

    public function test_account_from_another_center_cannot_be_linked(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $centerA
        );

        $personA = Person::factory()
            ->for($centerA)
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($personA)
            ->create();

        $accountB = $this->roleAccount(
            $centerB,
            $personB,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $accountB
            );
    }

    public function test_deactivated_account_cannot_be_linked(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        $account->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );
    }

    public function test_deactivated_staff_record_cannot_link_account(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->deactivated()
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );
    }

    public function test_repeated_link_to_same_account_is_idempotent(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $center
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );

        $this->assertSame(
            1,
            \App\Models\AuditRecord::query()
                ->where(
                    'action_type',
                    'teacher.account_linked'
                )
                ->where(
                    'subject_id',
                    $teacher->id
                )
                ->count()
        );
    }

    public function test_existing_link_cannot_be_replaced_by_another_account(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacherAccount =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::Teacher
            );

        $otherAccount =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::BranchManager
            );

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create([
                'user_id' =>
                $teacherAccount->id,
            ]);

        $this->establishCenter(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $otherAccount
            );
    }

    public function test_create_uses_persisted_person_scope_instead_of_tampered_memory_state(): void
    {
        $centerA = Center::factory()
            ->create();

        $centerB = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $centerA
        );

        $person = Person::factory()
            ->for($centerA)
            ->create();

        /*
         * Local mutation must not change authorization
         * or persisted ownership.
         */
        $person->center_id =
            $centerB->id;

        $this->establishCenter(
            $centerA
        );

        $teacher = $this->service()
            ->createTeacher(
                $actor,
                $person
            );

        $this->assertSame(
            $centerA->id,
            $teacher->center_id
        );

        $this->assertSame(
            $person->id,
            $teacher->person_id
        );
    }

    public function test_link_uses_persisted_account_role_instead_of_tampered_memory_state(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        /*
         * Pretend locally that the account has another role.
         * The service must re-read persisted account state.
         */
        $account->role_id =
            $this->role(
                SystemRole::FinanceEmployee
            )->id;

        $account->unsetRelation(
            'role'
        );

        $this->establishCenter(
            $center
        );

        $teacher = $this->service()
            ->linkTeacherAccount(
                $actor,
                $teacher,
                $account
            );

        $this->assertSame(
            $account->id,
            $teacher->user_id
        );
    }

    public function test_creation_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $this->establishCenter(
            $center
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock->shouldReceive('record')
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
                ->createTeacher(
                    $actor,
                    $person
                );

            $this->fail(
                'Expected audit failure was not thrown.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'teachers',
            [
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,
            ]
        );
    }

    public function test_account_link_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->create();

        $actor = $this->centerOwner(
            $center
        );

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

        $account = $this->roleAccount(
            $center,
            $person,
            SystemRole::Teacher
        );

        $this->establishCenter(
            $center
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock->shouldReceive('record')
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
                ->linkTeacherAccount(
                    $actor,
                    $teacher,
                    $account
                );

            $this->fail(
                'Expected audit failure was not thrown.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Audit failure.',
                $exception->getMessage()
            );
        }

        $teacher->refresh();

        $this->assertNull(
            $teacher->user_id
        );
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
        $person = Person::factory()
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
        SystemRole $role
    ): User {
        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role($role)->id,

                'status' =>
                AccountStatus::Active,
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
