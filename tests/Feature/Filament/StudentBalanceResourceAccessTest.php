<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\StudentBalances\StudentBalanceResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBalanceResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_students_with_active_financial_obligations_in_own_center(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $studentA =
            $this->createObligation(
                $branchA
            );

        $studentB =
            $this->createObligation(
                $branchB
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            StudentBalanceResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $studentA->id,
            $ids
        );

        $this->assertNotContains(
            $studentB->id,
            $ids
        );

        $this->assertTrue(
            StudentBalanceResource
                ::canViewAny()
        );

        $this->assertTrue(
            StudentBalanceResource
                ::canView(
                    $studentA
                )
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $studentB
                )
        );

        $this->assertNull(
            StudentBalanceResource
                ::resolveRecordRouteBinding(
                    $studentB
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_historical_branch_finance_even_after_student_moves(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $financialBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $newStudentBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $financialBranch
            );

        $student->forceFill([
            'branch_id' =>
            $newStudentBranch->id,
        ])->save();

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $financialBranch
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $financialBranch
        );

        $this->assertTrue(
            StudentBalanceResource
                ::canViewAny()
        );

        $this->assertTrue(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertSame(
            [
                $student->id,
            ],
            StudentBalanceResource
                ::getEloquentQuery()
                ->pluck(
                    'id'
                )
                ->all()
        );

        $this->assertNotSame(
            $financialBranch->id,
            $student
                ->fresh()
                ->branch_id
        );
    }

    public function test_branch_manager_does_not_see_student_with_finance_only_in_another_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $otherBranch
            );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertNull(
            StudentBalanceResource
                ::resolveRecordRouteBinding(
                    $student
                        ->getKey()
                )
        );

        $this->assertSame(
            0,
            StudentBalanceResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_finance_employee_sees_only_students_with_finance_in_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $ownStudent =
            $this->createObligation(
                $ownBranch
            );

        $otherStudent =
            $this->createObligation(
                $otherBranch
            );

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $ownBranch
        );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        $ids =
            StudentBalanceResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $ownStudent->id,
            $ids
        );

        $this->assertNotContains(
            $otherStudent->id,
            $ids
        );

        $this->assertTrue(
            StudentBalanceResource
                ::canView(
                    $ownStudent
                )
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $otherStudent
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed_with_stale_context(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $branch
            );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $assignment =
            $this->assignBranchManager(
                $manager,
                $branch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            StudentBalanceResource
                ::canViewAny()
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertSame(
            0,
            StudentBalanceResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_ended_finance_employee_assignment_fails_closed_with_stale_context(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $branch
            );

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $assignment =
            $this->assignFinanceEmployee(
                $finance,
                $branch
            );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $assignment->forceFill([
            'ended_at' =>
            now(),

            'active_marker' =>
            null,
        ])->save();

        $this->assertFalse(
            StudentBalanceResource
                ::canViewAny()
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertSame(
            0,
            StudentBalanceResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_student_with_only_voided_fees_is_not_listed(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $branch,
                EnrollmentFeeStatus::Voided
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertSame(
            0,
            StudentBalanceResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_other_roles_cannot_access_student_balances_resource(): void
    {
        $platformOwner =
            User::factory()
            ->create([
                'center_id' =>
                null,

                'person_id' =>
                null,

                'role_id' =>
                $this
                    ->role(
                        SystemRole::PlatformOwner
                    )
                    ->id,

                'status' =>
                AccountStatus::Active,
            ]);

        $this->actingAs(
            $platformOwner
        );

        app(TenantContext::class)
            ->establishPlatformScope();

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            StudentBalanceResource
                ::canViewAny()
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        foreach (
            [
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user =
                $this->createCenterUser(
                    $role,
                    $center
                );

            $this->actingAs(
                $user
            );

            app(TenantContext::class)
                ->establishCenterScope(
                    $center
                );

            app(BranchContext::class)
                ->clear();

            $this->assertFalse(
                StudentBalanceResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                StudentBalanceResource
                    ::getEloquentQuery()
                    ->count()
            );
        }
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $branch
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->clear();

        $this->assertFalse(
            StudentBalanceResource
                ::canViewAny()
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canView(
                    $student
                )
        );

        $this->assertSame(
            0,
            StudentBalanceResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_native_student_balance_crud_is_disabled(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $student =
            $this->createObligation(
                $branch
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canCreate()
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canEdit(
                    $student
                )
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canDelete(
                    $student
                )
        );

        $this->assertFalse(
            StudentBalanceResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            StudentBalanceResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                StudentBalanceResource
                    ::getPages()
            )
        );
    }

    private function createObligation(
        Branch $branch,
        EnrollmentFeeStatus $status =
        EnrollmentFeeStatus::Active
    ): Student {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            )
            ->create([
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,

                'status' =>
                $status,
            ]);

        return $student;
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
    }

    private function establishBranchContext(
        Center $center,
        Branch $branch
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );
    }

    private function assignBranchManager(
        User $manager,
        Branch $branch
    ): BranchManagerAssignment {
        return BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $manager->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function assignFinanceEmployee(
        User $user,
        Branch $branch
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment
            ::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $branch->center_id,

                'user_id' =>
                $user->id,

                'branch_id' =>
                $branch->id,

                'started_at' =>
                now(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->for(
                $center
            )
            ->create();

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this
                    ->role(
                        $role
                    )
                    ->id,

                'status' =>
                AccountStatus::Active,

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