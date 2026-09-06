<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EnrollmentFees\EnrollmentFeeResource;
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
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentFeeResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_fees_from_own_center(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $ownFee =
            $this->createFee(
                $branchA
            );

        $otherFee =
            $this->createFee(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            EnrollmentFeeResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertContains(
            $ownFee->id,
            $ids
        );

        $this->assertNotContains(
            $otherFee->id,
            $ids
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canView(
                    $ownFee
                )
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $otherFee
                )
        );

        $this->assertNull(
            EnrollmentFeeResource
                ::resolveRecordRouteBinding(
                    $otherFee
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_only_fees_from_assigned_branch(): void
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

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $ownFee =
            $this->createFee(
                $ownBranch
            );

        $otherFee =
            $this->createFee(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        $ids =
            EnrollmentFeeResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertSame(
            [
                $ownFee->id,
            ],
            $ids
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canView(
                    $ownFee
                )
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $otherFee
                )
        );

        $this->assertNull(
            EnrollmentFeeResource
                ::resolveRecordRouteBinding(
                    $otherFee
                        ->getKey()
                )
        );
    }

    public function test_finance_employee_sees_only_fees_from_assigned_branch(): void
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

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $ownBranch
        );

        $ownFee =
            $this->createFee(
                $ownBranch
            );

        $otherFee =
            $this->createFee(
                $otherBranch
            );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $ownBranch
        );

        $ids =
            EnrollmentFeeResource
            ::getEloquentQuery()
            ->pluck(
                'id'
            )
            ->all();

        $this->assertSame(
            [
                $ownFee->id,
            ],
            $ids
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertTrue(
            EnrollmentFeeResource
                ::canView(
                    $ownFee
                )
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $otherFee
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed_even_with_stale_context(): void
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

        $fee =
            $this->createFee(
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
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            EnrollmentFeeResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $fee
                )
        );
    }

    public function test_ended_finance_employee_assignment_fails_closed_even_with_stale_context(): void
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

        $fee =
            $this->createFee(
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
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            EnrollmentFeeResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $fee
                )
        );
    }

    public function test_other_roles_cannot_access_enrollment_fee_resource(): void
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
            EnrollmentFeeResource
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
                EnrollmentFeeResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                EnrollmentFeeResource
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $fee =
            $this->createFee(
                $branch
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
            EnrollmentFeeResource
                ::canViewAny()
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $fee
                )
        );

        $this->assertSame(
            0,
            EnrollmentFeeResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_fee_with_branch_inconsistent_with_enrollment_class_is_hidden(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $enrollmentBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $wrongFeeBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $fee =
            $this->createFee(
                $enrollmentBranch
            );

        /*
         * Deliberately corrupt only the financial Branch
         * relationship while keeping the same Center.
         *
         * The Resource must fail closed.
         */
        $fee->forceFill([
            'branch_id' =>
            $wrongFeeBranch->id,
        ])->save();

        $fee->refresh();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canView(
                    $fee
                )
        );

        $this->assertNull(
            EnrollmentFeeResource
                ::resolveRecordRouteBinding(
                    $fee->getKey()
                )
        );

        $this->assertSame(
            0,
            EnrollmentFeeResource
                ::getEloquentQuery()
                ->whereKey(
                    $fee->id
                )
                ->count()
        );
    }

    public function test_native_enrollment_fee_crud_is_disabled(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $fee =
            $this->createFee(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canCreate()
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canEdit(
                    $fee
                )
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canDelete(
                    $fee
                )
        );

        $this->assertFalse(
            EnrollmentFeeResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            EnrollmentFeeResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                EnrollmentFeeResource
                    ::getPages()
            )
        );
    }

    private function createFee(
        Branch $branch
    ): EnrollmentFee {
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

        return EnrollmentFee::factory()
            ->forEnrollment(
                $enrollment
            )
            ->active()
            ->create([
                'center_id' =>
                $branch->center_id,

                'branch_id' =>
                $branch->id,
            ]);
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
            ->for($center)
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