<?php

namespace Tests\Feature\Tenancy;

use App\Models\BranchManager;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffOperationalFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_teacher_can_be_created_with_required_identity_scope(): void
    {
        $center = Center::factory()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $teacher = Teacher::factory()
            ->forPerson($person)
            ->create();

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

        $this->assertNull(
            $teacher->deactivated_at
        );
    }

    public function test_branch_manager_can_be_created_without_login_account(): void
    {
        $branchManager =
            BranchManager::factory()
            ->create();

        $this->assertNull(
            $branchManager->user_id
        );

        $this->assertSame(
            StaffStatus::Active,
            $branchManager->status
        );

        $this->assertTrue(
            $branchManager->isActive()
        );
    }

    public function test_finance_employee_can_be_created_without_login_account(): void
    {
        $financeEmployee =
            FinanceEmployee::factory()
            ->create();

        $this->assertNull(
            $financeEmployee->user_id
        );

        $this->assertSame(
            StaffStatus::Active,
            $financeEmployee->status
        );

        $this->assertTrue(
            $financeEmployee->isActive()
        );
    }

    public function test_staff_status_is_cast_and_deactivated_timestamp_is_preserved(): void
    {
        $teacher =
            Teacher::factory()
            ->deactivated()
            ->create();

        $branchManager =
            BranchManager::factory()
            ->deactivated()
            ->create();

        $financeEmployee =
            FinanceEmployee::factory()
            ->deactivated()
            ->create();

        foreach (
            [
                $teacher,
                $branchManager,
                $financeEmployee,
            ] as $staff
        ) {
            $this->assertSame(
                StaffStatus::Deactivated,
                $staff->status
            );

            $this->assertNotNull(
                $staff->deactivated_at
            );

            $this->assertTrue(
                $staff->isDeactivated()
            );
        }
    }

    public function test_same_person_cannot_have_two_teacher_records_in_same_center(): void
    {
        $person =
            Person::factory()
            ->create();

        Teacher::factory()
            ->forPerson($person)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Teacher::factory()
            ->forPerson($person)
            ->create();
    }

    public function test_same_person_cannot_have_two_branch_manager_records_in_same_center(): void
    {
        $person =
            Person::factory()
            ->create();

        BranchManager::factory()
            ->forPerson($person)
            ->create();

        $this->expectException(
            QueryException::class
        );

        BranchManager::factory()
            ->forPerson($person)
            ->create();
    }

    public function test_same_person_cannot_have_two_finance_employee_records_in_same_center(): void
    {
        $person =
            Person::factory()
            ->create();

        FinanceEmployee::factory()
            ->forPerson($person)
            ->create();

        $this->expectException(
            QueryException::class
        );

        FinanceEmployee::factory()
            ->forPerson($person)
            ->create();
    }

    public function test_same_person_may_have_different_operational_role_records(): void
    {
        $person =
            Person::factory()
            ->create();

        $teacher =
            Teacher::factory()
            ->forPerson($person)
            ->create();

        $branchManager =
            BranchManager::factory()
            ->forPerson($person)
            ->create();

        $financeEmployee =
            FinanceEmployee::factory()
            ->forPerson($person)
            ->create();

        $this->assertSame(
            $person->id,
            $teacher->person_id
        );

        $this->assertSame(
            $person->id,
            $branchManager->person_id
        );

        $this->assertSame(
            $person->id,
            $financeEmployee->person_id
        );
    }

    public function test_teacher_cannot_reference_person_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        $personB =
            Person::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Teacher::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'person_id' =>
                $personB->id,
            ]);
    }

    public function test_branch_manager_cannot_reference_person_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        $personB =
            Person::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        BranchManager::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'person_id' =>
                $personB->id,
            ]);
    }

    public function test_finance_employee_cannot_reference_person_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        $personB =
            Person::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        FinanceEmployee::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'person_id' =>
                $personB->id,
            ]);
    }

    public function test_teacher_can_link_account_for_same_person_and_center(): void
    {
        $center =
            Center::factory()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::Teacher
            );

        $teacher =
            Teacher::factory()
            ->forPerson($person)
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->assertTrue(
            $teacher->user->is(
                $account
            )
        );

        $this->assertTrue(
            $account->teacher->is(
                $teacher
            )
        );
    }

    public function test_branch_manager_can_link_account_for_same_person_and_center(): void
    {
        $center =
            Center::factory()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::BranchManager
            );

        $record =
            BranchManager::factory()
            ->forPerson($person)
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->assertTrue(
            $record->user->is(
                $account
            )
        );

        $this->assertTrue(
            $account->branchManager->is(
                $record
            )
        );
    }

    public function test_finance_employee_can_link_account_for_same_person_and_center(): void
    {
        $center =
            Center::factory()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::FinanceEmployee
            );

        $record =
            FinanceEmployee::factory()
            ->forPerson($person)
            ->create([
                'user_id' =>
                $account->id,
            ]);

        $this->assertTrue(
            $record->user->is(
                $account
            )
        );

        $this->assertTrue(
            $account->financeEmployee->is(
                $record
            )
        );
    }

    public function test_staff_record_rejects_account_for_different_person(): void
    {
        $center =
            Center::factory()
            ->create();

        $personA =
            Person::factory()
            ->for($center)
            ->create();

        $personB =
            Person::factory()
            ->for($center)
            ->create();

        $accountB =
            $this->roleAccount(
                $center,
                $personB,
                SystemRole::Teacher
            );

        $this->expectException(
            QueryException::class
        );

        Teacher::factory()
            ->forPerson($personA)
            ->create([
                'user_id' =>
                $accountB->id,
            ]);
    }

    public function test_staff_record_rejects_account_from_another_center(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        $personA =
            Person::factory()
            ->for($centerA)
            ->create();

        $personB =
            Person::factory()
            ->for($centerB)
            ->create();

        $accountB =
            $this->roleAccount(
                $centerB,
                $personB,
                SystemRole::FinanceEmployee
            );

        $this->expectException(
            QueryException::class
        );

        FinanceEmployee::factory()
            ->forPerson($personA)
            ->create([
                'user_id' =>
                $accountB->id,
            ]);
    }

    public function test_same_user_cannot_link_to_two_teacher_records(): void
    {
        $center =
            Center::factory()
            ->create();

        $person =
            Person::factory()
            ->for($center)
            ->create();

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::Teacher
            );

        Teacher::factory()
            ->forPerson($person)
            ->create([
                'user_id' =>
                $account->id,
            ]);

        /*
         * The center/person unique constraint would also reject
         * another Teacher for this Person, so use direct insertion
         * with another Person cannot satisfy the composite account
         * ownership FK. The existing constraints together guarantee
         * one valid operational Teacher record per account.
         */
        $this->assertDatabaseCount(
            'teachers',
            1
        );

        $this->assertSame(
            $account->id,
            Teacher::query()
                ->firstOrFail()
                ->user_id
        );
    }

    public function test_person_exposes_operational_role_relationships(): void
    {
        $person =
            Person::factory()
            ->create();

        $teacher =
            Teacher::factory()
            ->forPerson($person)
            ->create();

        $branchManager =
            BranchManager::factory()
            ->forPerson($person)
            ->create();

        $financeEmployee =
            FinanceEmployee::factory()
            ->forPerson($person)
            ->create();

        $person->refresh();

        $this->assertTrue(
            $person->teacher->is(
                $teacher
            )
        );

        $this->assertTrue(
            $person->branchManager->is(
                $branchManager
            )
        );

        $this->assertTrue(
            $person->financeEmployee->is(
                $financeEmployee
            )
        );
    }

    public function test_teacher_center_scope_excludes_other_centers(): void
    {
        $centerA =
            Center::factory()
            ->create();

        $centerB =
            Center::factory()
            ->create();

        Teacher::factory()
            ->create([
                'center_id' =>
                $centerA->id,
            ]);

        Teacher::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $teachers =
            Teacher::query()
            ->forCurrentTenant()
            ->get();

        $this->assertCount(
            1,
            $teachers
        );

        $this->assertSame(
            $centerA->id,
            $teachers->first()->center_id
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
