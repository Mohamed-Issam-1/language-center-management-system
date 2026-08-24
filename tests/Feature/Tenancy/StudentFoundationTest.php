<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->create();

        $this->assertSame(
            $center->id,
            $student->center_id
        );

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $person->id,
            $student->person_id
        );

        $this->assertNull(
            $student->user_id
        );

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertNull(
            $student->archived_at
        );
    }

    public function test_new_student_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::query()
            ->create([
                'center_id' => $center->id,
                'branch_id' => $branch->id,
                'person_id' => $person->id,
                'user_id' => null,
            ]);

        $student->refresh();

        $this->assertSame(
            StudentStatus::Active,
            $student->status
        );

        $this->assertTrue(
            $student->isActive()
        );

        $this->assertFalse(
            $student->isArchived()
        );

        $this->assertNull(
            $student->archived_at
        );
    }

    public function test_student_casts_active_and_archived_lifecycle_state(): void
    {
        $activeStudent = Student::factory()
            ->active()
            ->create();

        $archivedStudent = Student::factory()
            ->archived()
            ->create();

        $this->assertSame(
            StudentStatus::Active,
            $activeStudent->status
        );

        $this->assertTrue(
            $activeStudent->isActive()
        );

        $this->assertFalse(
            $activeStudent->isArchived()
        );

        $this->assertNull(
            $activeStudent->archived_at
        );

        $this->assertSame(
            StudentStatus::Archived,
            $archivedStudent->status
        );

        $this->assertFalse(
            $archivedStudent->isActive()
        );

        $this->assertTrue(
            $archivedStudent->isArchived()
        );

        $this->assertNotNull(
            $archivedStudent->archived_at
        );
    }

    public function test_student_belongs_to_center_branch_person_and_optional_user_account(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $user = User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' => $this->role(
                    SystemRole::Student
                )->id,
                'status' => AccountStatus::Active,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->create([
                'user_id' => $user->id,
            ]);

        $this->assertTrue(
            $student->center->is(
                $center
            )
        );

        $this->assertTrue(
            $student->branch->is(
                $branch
            )
        );

        $this->assertTrue(
            $student->person->is(
                $person
            )
        );

        $this->assertTrue(
            $student->user->is(
                $user
            )
        );

        $this->assertTrue(
            $center->students
                ->contains($student)
        );

        $this->assertTrue(
            $branch->students
                ->contains($student)
        );

        $this->assertTrue(
            $person->student->is(
                $student
            )
        );

        $this->assertTrue(
            $user->student->is(
                $student
            )
        );
    }

    public function test_same_person_cannot_have_two_student_records_in_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        Student::factory()
            ->forBranch($branchA)
            ->forPerson($person)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Student::factory()
            ->forBranch($branchB)
            ->forPerson($person)
            ->create();
    }

    public function test_database_rejects_student_branch_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $personA = Person::factory()
            ->for($centerA)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Student::query()
            ->create([
                'center_id' => $centerA->id,
                'branch_id' => $branchB->id,
                'person_id' => $personA->id,
                'user_id' => null,
                'status' => StudentStatus::Active,
            ]);
    }

    public function test_database_rejects_student_person_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $personB = Person::factory()
            ->for($centerB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Student::query()
            ->create([
                'center_id' => $centerA->id,
                'branch_id' => $branchA->id,
                'person_id' => $personB->id,
                'user_id' => null,
                'status' => StudentStatus::Active,
            ]);
    }

    public function test_database_rejects_linking_student_to_account_for_another_person(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $studentPerson = Person::factory()
            ->for($center)
            ->create();

        $otherPerson = Person::factory()
            ->for($center)
            ->create();

        $otherAccount = User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $otherPerson->id,
                'role_id' => $this->role(
                    SystemRole::Student
                )->id,
                'status' => AccountStatus::Active,
            ]);

        $this->expectException(
            QueryException::class
        );

        Student::query()
            ->create([
                'center_id' => $center->id,
                'branch_id' => $branch->id,
                'person_id' => $studentPerson->id,
                'user_id' => $otherAccount->id,
                'status' => StudentStatus::Active,
            ]);
    }

    public function test_student_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $studentA = Student::factory()
            ->forBranch($branchA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = Student::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $studentA->id,
            $ids
        );

        $this->assertNotContains(
            $studentB->id,
            $ids
        );
    }

    public function test_student_branch_scope_restricts_to_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $studentA = Student::factory()
            ->forBranch($branchA)
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $ids = Student::query()
            ->forCurrentBranch()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$studentA->id],
            $ids
        );

        $this->assertNotContains(
            $studentB->id,
            $ids
        );
    }

    public function test_center_wide_branch_context_returns_all_students_in_current_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $studentA = Student::factory()
            ->forBranch($branchA)
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->create();

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($otherCenter)
            ->active()
            ->create();

        $otherStudent = Student::factory()
            ->forBranch($otherBranch)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $ids = Student::query()
            ->forCurrentBranch()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains(
            $studentA->id,
            $ids
        );

        $this->assertContains(
            $studentB->id,
            $ids
        );

        $this->assertNotContains(
            $otherStudent->id,
            $ids
        );

        $this->assertCount(
            2,
            $ids
        );
    }

    public function test_archiving_student_preserves_record_and_relationships(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->forPerson($person)
            ->active()
            ->create();

        $student->update([
            'status' => StudentStatus::Archived,
            'archived_at' => now(),
        ]);

        $student->refresh();

        $this->assertSame(
            StudentStatus::Archived,
            $student->status
        );

        $this->assertTrue(
            $student->isArchived()
        );

        $this->assertFalse(
            $student->isActive()
        );

        $this->assertNotNull(
            $student->archived_at
        );

        $this->assertSame(
            $center->id,
            $student->center_id
        );

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $person->id,
            $student->person_id
        );

        $this->assertDatabaseHas(
            'students',
            [
                'id' => $student->id,
                'center_id' => $center->id,
                'branch_id' => $branch->id,
                'person_id' => $person->id,
                'status' => StudentStatus::Archived->value,
            ]
        );

        $this->assertDatabaseHas(
            'people',
            [
                'id' => $person->id,
                'center_id' => $center->id,
            ]
        );
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
