<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Registration\RegistrationReviewService;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Database\Seeders\RoleSeeder;

class RegistrationReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_can_select_center_owner_role(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor = $this->platformOwner();

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishPlatformScope();

        $request =
            $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::CenterOwner
            );

        $this->assertSame(
            $this->role(
                SystemRole::CenterOwner
            )->id,
            $request->selected_role_id
        );

        $this->assertNull(
            $request->selected_branch_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.role_selected',

                'subject_type' =>
                'registration_requests',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_platform_owner_cannot_select_student_role(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->platformOwner();

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishPlatformScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::Student
            );
    }

    public function test_center_owner_can_select_staff_and_student_roles(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        foreach (
            [
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $request =
                RegistrationRequest::factory()
                ->for($center)
                ->create();

            $reviewed =
                $this->service()
                ->selectRole(
                    $actor,
                    $request,
                    $role
                );

            $this->assertSame(
                $this->role($role)->id,
                $reviewed->selected_role_id
            );
        }
    }

    public function test_center_owner_cannot_select_center_owner_role(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::CenterOwner
            );
    }

    public function test_branch_manager_can_select_only_student_role(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::BranchManager
            );

        $this->assignBranchManager(
            $actor,
            $branch
        );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $studentRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $reviewed =
            $this->service()
            ->selectRole(
                $actor,
                $studentRequest,
                SystemRole::Student
            );

        $this->assertSame(
            $this->role(
                SystemRole::Student
            )->id,
            $reviewed->selected_role_id
        );

        $teacherRequest =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->selectRole(
                $actor,
                $teacherRequest,
                SystemRole::Teacher
            );
    }

    public function test_role_change_clears_previous_branch_selection(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,

                'selected_branch_id' =>
                $branch->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $request =
            $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::Teacher
            );

        $this->assertSame(
            $this->role(
                SystemRole::Teacher
            )->id,
            $request->selected_role_id
        );

        $this->assertNull(
            $request->selected_branch_id
        );
    }

    public function test_center_owner_can_select_active_branch_for_student(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create([
                'status' =>
                BranchStatus::Active,
            ]);

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $request =
            $this->service()
            ->selectBranch(
                $actor,
                $request,
                $branch
            );

        $this->assertSame(
            $branch->id,
            $request->selected_branch_id
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.branch_selected',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_teacher_registration_cannot_select_branch(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->selectBranch(
                $actor,
                $request,
                $branch
            );
    }

    public function test_branch_manager_can_select_only_own_assigned_branch(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $ownBranch =
            Branch::factory()
            ->for($center)
            ->create();

        $otherBranch =
            Branch::factory()
            ->for($center)
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::BranchManager
            );

        $this->assignBranchManager(
            $actor,
            $ownBranch
        );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->selectBranch(
                $actor,
                $request,
                $otherBranch
            );
    }

    public function test_inactive_branch_cannot_be_selected(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->create([
                'status' =>
                BranchStatus::Deactivated,
            ]);

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'selected_role_id' =>
                $this->role(
                    SystemRole::Student
                )->id,
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->selectBranch(
                $actor,
                $request,
                $branch
            );
    }

    public function test_center_owner_can_reject_pending_request(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $request =
            $this->service()
            ->reject(
                $actor,
                $request,
                'Identity information could not be verified.'
            );

        $this->assertSame(
            RegistrationRequestStatus::Rejected,
            $request->status
        );

        $this->assertSame(
            $actor->id,
            $request->reviewed_by_user_id
        );

        $this->assertNotNull(
            $request->reviewed_at
        );

        $this->assertSame(
            'Identity information could not be verified.',
            $request->rejection_reason
        );

        $this->assertNull(
            $request->pending_marker
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.rejected',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_rejection_requires_reason(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->reject(
                $actor,
                $request,
                '   '
            );
    }

    public function test_rejected_request_cannot_be_modified_again(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'status' =>
                RegistrationRequestStatus::Rejected,

                'pending_marker' =>
                null,

                'reviewed_at' =>
                now(),

                'rejection_reason' =>
                'Rejected earlier.',
            ]);

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::Student
            );
    }

    public function test_review_uses_persisted_request_scope_instead_of_tampered_memory_state(): void
    {
        $centerA =
            \App\Models\Center::factory()
            ->create();

        $centerB =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $centerA,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($centerA)
            ->create();

        /*
         * Local mutation must not affect authorization.
         */
        $request->center_id =
            $centerB->id;

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $reviewed =
            $this->service()
            ->selectRole(
                $actor,
                $request,
                SystemRole::Teacher
            );

        $this->assertSame(
            $centerA->id,
            $reviewed->center_id
        );

        $this->assertSame(
            $this->role(
                SystemRole::Teacher
            )->id,
            $reviewed->selected_role_id
        );
    }

    public function test_role_selection_rolls_back_when_audit_recording_fails(): void
    {
        $center =
            \App\Models\Center::factory()
            ->create();

        $actor =
            $this->centerActor(
                $center,
                SystemRole::CenterOwner
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
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
                ->selectRole(
                    $actor,
                    $request,
                    SystemRole::Teacher
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

        $request->refresh();

        $this->assertNull(
            $request->selected_role_id
        );

        $this->assertNull(
            $request->selected_branch_id
        );
    }

    private function service(): RegistrationReviewService
    {
        return app(
            RegistrationReviewService::class
        );
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->firstOrCreate(
                [
                    'code' =>
                    $role->value,
                ],
                [
                    'name' =>
                    $role->label(),
                ]
            );
    }

    private function platformOwner(): User
    {
        return User::factory()
            ->create([
                'center_id' => null,
                'person_id' => null,

                'role_id' =>
                $this->role(
                    SystemRole::PlatformOwner
                )->id,
            ]);
    }

    private function centerActor(
        \App\Models\Center $center,
        SystemRole $role
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
            ]);
    }

    private function assignBranchManager(
        User $manager,
        Branch $branch
    ): void {
        DB::table(
            'branch_manager_assignments'
        )->insert([
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

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);
    }
}
