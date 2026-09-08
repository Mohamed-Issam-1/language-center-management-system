<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AuditRecords\AuditRecordResource;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditRecordResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_sees_all_audit_records_in_own_center_only(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $branchA1 =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchA2 =
            Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $actor =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $centerA
            );

        $centerWideRecord =
            AuditRecord::factory()
            ->forCenter(
                $centerA
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'center.updated',
            ]);

        $branchRecordA1 =
            AuditRecord::factory()
            ->forBranch(
                $branchA1
            )
            ->byActor(
                $actor
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $branchRecordA2 =
            AuditRecord::factory()
            ->forBranch(
                $branchA2
            )
            ->byActor(
                $actor
            )
            ->create([
                'action_type' =>
                'class.updated',
            ]);

        $otherCenterRecord =
            AuditRecord::factory()
            ->forCenter(
                $centerB
            )
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $ids =
            AuditRecordResource
            ::getEloquentQuery()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [
                $centerWideRecord->id,
                $branchRecordA1->id,
                $branchRecordA2->id,
            ],
            $ids
        );

        $this->assertNotContains(
            $otherCenterRecord->id,
            $ids
        );

        $this->assertTrue(
            AuditRecordResource
                ::canViewAny()
        );

        $this->assertTrue(
            AuditRecordResource
                ::canView(
                    $centerWideRecord
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canView(
                    $otherCenterRecord
                )
        );
    }

    public function test_branch_manager_sees_only_audit_records_from_assigned_branch(): void
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

        $ownBranchRecord =
            AuditRecord::factory()
            ->forBranch(
                $ownBranch
            )
            ->byActor(
                $manager
            )
            ->create();

        $otherBranchRecord =
            AuditRecord::factory()
            ->forBranch(
                $otherBranch
            )
            ->byActor(
                $manager
            )
            ->create();

        $centerWideRecord =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $manager
            )
            ->create();

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        $this->assertTrue(
            AuditRecordResource
                ::canViewAny()
        );

        $this->assertSame(
            [
                $ownBranchRecord->id,
            ],
            AuditRecordResource
                ::getEloquentQuery()
                ->pluck('id')
                ->all()
        );

        $this->assertTrue(
            AuditRecordResource
                ::canView(
                    $ownBranchRecord
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canView(
                    $otherBranchRecord
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canView(
                    $centerWideRecord
                )
        );

        $this->assertNull(
            AuditRecordResource
                ::resolveRecordRouteBinding(
                    $otherBranchRecord
                        ->getKey()
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

        $record =
            AuditRecord::factory()
            ->forBranch(
                $branch
            )
            ->byActor(
                $manager
            )
            ->create();

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
            AuditRecordResource
                ::canViewAny()
        );

        $this->assertFalse(
            AuditRecordResource
                ::canView(
                    $record
                )
        );

        $this->assertSame(
            0,
            AuditRecordResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_unauthorized_roles_cannot_access_audit_records(): void
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

        app(
            TenantContext::class
        )->establishPlatformScope();

        app(
            BranchContext::class
        )->clear();

        $this->assertFalse(
            $platformOwner
                ->hasPermission(
                    SystemPermission
                    ::ViewAuditRecords
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canViewAny()
        );

        $center =
            Center::factory()
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

            app(
                TenantContext::class
            )->establishCenterScope(
                $center
            );

            app(
                BranchContext::class
            )->clear();

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission
                    ::ViewAuditRecords
                )
            );

            $this->assertFalse(
                AuditRecordResource
                    ::canViewAny()
            );

            $this->assertSame(
                0,
                AuditRecordResource
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $record =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create();

        $this->actingAs(
            $owner
        );

        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->clear();

        $this->assertFalse(
            AuditRecordResource
                ::canViewAny()
        );

        $this->assertFalse(
            AuditRecordResource
                ::canView(
                    $record
                )
        );

        $this->assertSame(
            0,
            AuditRecordResource
                ::getEloquentQuery()
                ->count()
        );
    }

    public function test_audit_record_resource_is_strictly_read_only(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $record =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create();

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->assertFalse(
            AuditRecordResource
                ::canCreate()
        );

        $this->assertFalse(
            AuditRecordResource
                ::canEdit(
                    $record
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canDelete(
                    $record
                )
        );

        $this->assertFalse(
            AuditRecordResource
                ::canDeleteAny()
        );

        $this->assertFalse(
            AuditRecordResource
                ::canReplicate(
                    $record
                )
        );

        $this->assertSame(
            [],
            AuditRecordResource
                ::getGloballySearchableAttributes()
        );

        $this->assertSame(
            [
                'index',
                'view',
            ],
            array_keys(
                AuditRecordResource
                    ::getPages()
            )
        );
    }

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->establishCenterWideScope();
    }

    private function establishBranchManagerContext(
        Center $center,
        Branch $branch
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->establishBranchScope(
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