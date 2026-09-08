<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AuditRecords\AuditRecordResource;
use App\Filament\Resources\AuditRecords\Pages\ListAuditRecords;
use App\Filament\Resources\AuditRecords\Pages\ViewAuditRecord;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditRecordResourcePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_list_page_renders_only_records_from_own_center(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA,
                '70000001'
            );

        $actor =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $centerA,
                '70000002'
            );

        $centerRecord =
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

                'subject_type' =>
                'centers',

                'subject_id' =>
                $centerA->id,
            ]);

        $branchRecord =
            AuditRecord::factory()
            ->forBranch(
                $branchA
            )
            ->byActor(
                $actor
            )
            ->create([
                'action_type' =>
                'student.updated',

                'subject_type' =>
                'students',

                'subject_id' =>
                101,
            ]);

        $foreignRecord =
            AuditRecord::factory()
            ->forCenter(
                $centerB
            )
            ->create([
                'action_type' =>
                'center.updated',
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->assertCanSeeTableRecords([
                $centerRecord,
                $branchRecord,
            ])
            ->assertCanNotSeeTableRecords([
                $foreignRecord,
            ]);
    }

    public function test_center_owner_can_filter_by_actor_account(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center,
                '71000000'
            );

        $actorA =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center,
                '71000001'
            );

        $actorB =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center,
                '71000002'
            );

        $recordA =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $actorA
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $recordB =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $actorB
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->filterTable(
                'actor',
                [
                    'account_login_identifier' =>
                    '71000001',
                ]
            )
            ->assertCanSeeTableRecords([
                $recordA,
            ])
            ->assertCanNotSeeTableRecords([
                $recordB,
            ]);
    }

    public function test_center_owner_can_filter_by_action_type(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center,
                '72000000'
            );

        $target =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $other =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'class.updated',
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->filterTable(
                'action',
                [
                    'action_type' =>
                    'student.updated',
                ]
            )
            ->assertCanSeeTableRecords([
                $target,
            ])
            ->assertCanNotSeeTableRecords([
                $other,
            ]);
    }

    public function test_center_owner_can_filter_by_subject_type_and_id(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center,
                '73000000'
            );

        $target =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'student.updated',

                'subject_type' =>
                'students',

                'subject_id' =>
                501,
            ]);

        $differentId =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'student.updated',

                'subject_type' =>
                'students',

                'subject_id' =>
                502,
            ]);

        $differentType =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'course.updated',

                'subject_type' =>
                'courses',

                'subject_id' =>
                501,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->filterTable(
                'subject',
                [
                    'subject_type' =>
                    'students',

                    'subject_id' =>
                    501,
                ]
            )
            ->assertCanSeeTableRecords([
                $target,
            ])
            ->assertCanNotSeeTableRecords([
                $differentId,
                $differentType,
            ]);
    }

    public function test_center_owner_can_filter_by_occurrence_date_range(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center,
                '74000000'
            );

        $recent =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'occurred_at' =>
                today()
                    ->setTime(
                        12,
                        0
                    ),
            ]);

        $old =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'occurred_at' =>
                today()
                    ->subDays(10)
                    ->setTime(
                        12,
                        0
                    ),
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->filterTable(
                'occurred_at',
                [
                    'from' =>
                    today()
                        ->subDay()
                        ->toDateString(),

                    'until' =>
                    today()
                        ->addDay()
                        ->toDateString(),
                ]
            )
            ->assertCanSeeTableRecords([
                $recent,
            ])
            ->assertCanNotSeeTableRecords([
                $old,
            ]);
    }

    public function test_view_page_renders_audit_event_and_payloads(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center,
                '75000000'
            );

        $record =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $owner
            )
            ->create([
                'action_type' =>
                'center.updated',

                'subject_type' =>
                'centers',

                'subject_id' =>
                $center->id,

                'before_values' => [
                    'name' =>
                    'Old Center Name',
                ],

                'after_values' => [
                    'name' =>
                    'New Center Name',
                ],

                'metadata' => [
                    'source' =>
                    'filament-test',
                ],
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ViewAuditRecord::class,
            [
                'record' =>
                $record
                    ->getRouteKey(),
            ]
        )
            ->assertSuccessful()
            ->assertSee(
                'Audit Event'
            )
            ->assertSee(
                'center.updated'
            )
            ->assertSee(
                'centers'
            )
            ->assertSee(
                'Before Values'
            )
            ->assertSee(
                'Old Center Name'
            )
            ->assertSee(
                'After Values'
            )
            ->assertSee(
                'New Center Name'
            )
            ->assertSee(
                'Metadata'
            )
            ->assertSee(
                'filament-test'
            );
    }

    public function test_branch_manager_list_page_renders_only_assigned_branch_records(): void
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
                $center,
                '76000000'
            );

        $this->assignBranchManager(
            $manager,
            $ownBranch
        );

        $ownRecord =
            AuditRecord::factory()
            ->forBranch(
                $ownBranch
            )
            ->byActor(
                $manager
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $otherRecord =
            AuditRecord::factory()
            ->forBranch(
                $otherBranch
            )
            ->byActor(
                $manager
            )
            ->create([
                'action_type' =>
                'student.updated',
            ]);

        $centerWideRecord =
            AuditRecord::factory()
            ->forCenter(
                $center
            )
            ->byActor(
                $manager
            )
            ->create([
                'action_type' =>
                'center.updated',
            ]);

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $ownBranch
        );

        Livewire::test(
            ListAuditRecords::class
        )
            ->assertSuccessful()
            ->assertCanSeeTableRecords([
                $ownRecord,
            ])
            ->assertCanNotSeeTableRecords([
                $otherRecord,
                $centerWideRecord,
            ]);
    }

    public function test_unauthorized_admin_account_cannot_open_audit_records_route(): void
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

                'must_change_password' =>
                false,
            ]);

        $this
            ->actingAs(
                $platformOwner
            )
            ->get(
                AuditRecordResource::getUrl(
                    'index'
                )
            )
            ->assertForbidden();
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
        Center $center,
        string $loginIdentifier
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

                'account_login_identifier' =>
                $loginIdentifier,

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