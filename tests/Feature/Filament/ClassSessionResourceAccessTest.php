<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSessionResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_only_sessions_from_own_center(): void
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

        $ownSession =
            $this->createSession(
                $branchA
            );

        $otherSession =
            $this->createSession(
                $branchB
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            ClassSessionResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownSession->id,
            $ids
        );

        $this->assertNotContains(
            $otherSession->id,
            $ids
        );

        $this->assertTrue(
            ClassSessionResource
                ::canViewAny()
        );

        $this->assertTrue(
            ClassSessionResource
                ::canView(
                    $ownSession
                )
        );

        $this->assertFalse(
            ClassSessionResource
                ::canView(
                    $otherSession
                )
        );

        $this->assertNull(
            ClassSessionResource
                ::resolveRecordRouteBinding(
                    $otherSession
                        ->getKey()
                )
        );
    }

    public function test_branch_manager_sees_only_sessions_from_assigned_branch(): void
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

        $ownSession =
            $this->createSession(
                $ownBranch
            );

        $otherSession =
            $this->createSession(
                $otherBranch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $ids =
            ClassSessionResource
            ::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $ownSession->id,
            $ids
        );

        $this->assertNotContains(
            $otherSession->id,
            $ids
        );

        $this->assertTrue(
            ClassSessionResource
                ::canViewAny()
        );

        $this->assertTrue(
            ClassSessionResource
                ::canView(
                    $ownSession
                )
        );

        $this->assertFalse(
            ClassSessionResource
                ::canView(
                    $otherSession
                )
        );

        $this->assertNull(
            ClassSessionResource
                ::resolveRecordRouteBinding(
                    $otherSession
                        ->getKey()
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
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
            ClassSessionResource
                ::canViewAny()
        );

        $this->assertSame(
            0,
            ClassSessionResource
                ::getEloquentQuery()
                ->count()
        );

        $this->assertFalse(
            ClassSessionResource
                ::canView(
                    $session
                )
        );

        $this->assertNull(
            ClassSessionResource
                ::resolveRecordRouteBinding(
                    $session
                        ->getKey()
                )
        );
    }

    public function test_other_roles_cannot_access_class_session_resource(): void
    {
        $roles = [
            SystemRole::PlatformOwner,
            SystemRole::FinanceEmployee,
            SystemRole::Teacher,
            SystemRole::Student,
        ];

        foreach (
            $roles as $role
        ) {
            $center =
                Center::factory()
                ->active()
                ->create();

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
                ->establishCenterWideScope();

            $this->assertFalse(
                ClassSessionResource
                    ::canViewAny(),
                "Role {$role->value} unexpectedly accessed Class Sessions."
            );
        }
    }

    public function test_native_class_session_crud_is_disabled(): void
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

        $session =
            $this->createSession(
                $branch
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            ClassSessionResource
                ::canCreate()
        );

        $this->assertFalse(
            ClassSessionResource
                ::canEdit(
                    $session
                )
        );

        $this->assertFalse(
            ClassSessionResource
                ::canDelete(
                    $session
                )
        );

        $this->assertFalse(
            ClassSessionResource
                ::canDeleteAny()
        );

        $this->assertSame(
            [],
            ClassSessionResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                ClassSessionResource
                    ::getPages()
            )
        );
    }

    private function createSession(
        Branch $branch
    ): ClassSession {
        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        return ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
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