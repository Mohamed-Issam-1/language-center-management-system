<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
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

class EnrollmentResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_enrollments_from_own_center(): void
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

        $enrollmentA =
            $this->createEnrollment(
                $branchA
            );

        $enrollmentB =
            $this->createEnrollment(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $visibleIds =
            EnrollmentResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $enrollmentA->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            EnrollmentResource
                ::canViewAny()
        );

        $this->assertTrue(
            EnrollmentResource
                ::canView(
                    $enrollmentA
                )
        );

        $this->assertFalse(
            EnrollmentResource
                ::canView(
                    $enrollmentB
                )
        );

        $this->assertNull(
            EnrollmentResource
                ::resolveRecordRouteBinding(
                    $enrollmentB
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_only_enrollments_fully_inside_assigned_branch(): void
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

        $ownEnrollment =
            $this->createEnrollment(
                $ownBranch
            );

        $otherEnrollment =
            $this->createEnrollment(
                $otherBranch
            );

        /*
         * Same Center but mismatched Student/Class branches.
         *
         * Database Center constraints allow this fixture so the
         * Resource can prove that its operational Branch scope
         * independently rejects it.
         */
        $ownStudent =
            Student::factory()
            ->forBranch(
                $ownBranch
            )
            ->active()
            ->create();

        $otherClass =
            CourseClass::factory()
            ->forBranch(
                $otherBranch
            )
            ->planned()
            ->create();

        $mixedEnrollment =
            Enrollment::factory()
            ->forStudent(
                $ownStudent
            )
            ->forCourseClass(
                $otherClass
            )
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $visibleIds =
            EnrollmentResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [
                $ownEnrollment->id,
            ],
            $visibleIds
        );

        $this->assertTrue(
            EnrollmentResource
                ::canViewAny()
        );

        $this->assertTrue(
            EnrollmentResource
                ::canView(
                    $ownEnrollment
                )
        );

        $this->assertFalse(
            EnrollmentResource
                ::canView(
                    $otherEnrollment
                )
        );

        $this->assertFalse(
            EnrollmentResource
                ::canView(
                    $mixedEnrollment
                )
        );

        $this->assertNull(
            EnrollmentResource
                ::resolveRecordRouteBinding(
                    $otherEnrollment
                        ->getKey()
                )
        );

        $this->assertNull(
            EnrollmentResource
                ::resolveRecordRouteBinding(
                    $mixedEnrollment
                        ->getKey()
                )
        );
    }

    public function test_ended_branch_manager_assignment_fails_closed_even_with_stale_branch_context(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        /*
         * Simulate stale request/application state after the
         * persisted operational assignment has ended.
         */
        $assignment->update([
            'ended_at' => now(),
            'active_marker' => null,
        ]);

        $this->assertFalse(
            EnrollmentResource
                ::canViewAny()
        );

        $this->assertSame(
            [],
            EnrollmentResource
                ::getEloquentQuery()
                ->pluck('id')
                ->all()
        );

        $this->assertFalse(
            EnrollmentResource
                ::canView(
                    $enrollment
                )
        );

        $this->assertNull(
            EnrollmentResource
                ::resolveRecordRouteBinding(
                    $enrollment
                        ->getKey()
                )
        );
    }

    public function test_other_roles_cannot_access_enrollment_resource(): void
    {
        $platformOwner =
            User::factory()
            ->create([
                'center_id' => null,
                'person_id' => null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,

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
            EnrollmentResource
                ::canViewAny()
        );

        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        foreach (
            [
                SystemRole::FinanceEmployee,
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
                ->establishBranchScope(
                    $branch
                );

            $this->assertFalse(
                EnrollmentResource
                    ::canViewAny()
            );

            $this->assertSame(
                [],
                EnrollmentResource
                    ::getEloquentQuery()
                    ->pluck('id')
                    ->all()
            );
        }
    }

    public function test_native_enrollment_crud_is_disabled(): void
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

        $enrollment =
            $this->createEnrollment(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            EnrollmentResource
                ::canCreate()
        );

        $this->assertFalse(
            EnrollmentResource
                ::canEdit(
                    $enrollment
                )
        );

        $this->assertFalse(
            EnrollmentResource
                ::canDelete(
                    $enrollment
                )
        );

        $this->assertFalse(
            EnrollmentResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            EnrollmentResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                EnrollmentResource
                    ::getPages()
            )
        );
    }

    private function createEnrollment(
        Branch $branch
    ): Enrollment {
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
            ->planned()
            ->create();

        return Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->create();
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

    private function establishBranchManagerContext(
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
                $this->role(
                    $role
                )->id,

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