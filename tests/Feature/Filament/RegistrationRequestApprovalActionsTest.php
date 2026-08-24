<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\RegistrationRequests\Pages\ListRegistrationRequests;
use App\Filament\Resources\RegistrationRequests\RegistrationRequestResource;
use App\Mail\RegistrationCredentialsMail;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class RegistrationRequestApprovalActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_approve_teacher_and_credentials_are_sent(): void
    {
        Mail::fake();

        $center =
            $this->center(
                '31'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Teacher,
                nationalId: '910000001',
                email: 'teacher.approval@example.test'
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            )
            ->callAction(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Approved,
            $request->status
        );

        $this->assertNull(
            $request->pending_marker
        );

        $person =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'national_id_number',
                '910000001'
            )
            ->firstOrFail();

        $account =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $person->id
            )
            ->where(
                'role_id',
                $this->role(
                    SystemRole::Teacher
                )->id
            )
            ->firstOrFail();

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNotNull(
            $account->account_login_identifier
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ): bool {
                return $mail->hasTo(
                    'teacher.approval@example.test'
                );
            }
        );
    }

    public function test_delivery_failure_does_not_roll_back_filament_approval(): void
    {
        $center =
            $this->center(
                '32'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Teacher,
                nationalId: '910000002',
                email: 'delivery.failure@example.test'
            );

        $failingDelivery =
            \Mockery::mock(
                RegistrationCredentialsDeliveryService::class
            );

        $failingDelivery
            ->shouldReceive(
                'deliver'
            )
            ->once()
            ->andThrow(
                new LogicException(
                    'Simulated credential delivery failure.'
                )
            );

        $this->app->instance(
            RegistrationCredentialsDeliveryService::class,
            $failingDelivery
        );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->callAction(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Approved,
            $request->status
        );

        $this->assertNull(
            $request->pending_marker
        );

        $person =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'national_id_number',
                '910000002'
            )
            ->firstOrFail();

        $account =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $person->id
            )
            ->where(
                'role_id',
                $this->role(
                    SystemRole::Teacher
                )->id
            )
            ->firstOrFail();

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'registration_request.approved',

                'subject_id' =>
                $request->id,
            ]
        );
    }

    public function test_branch_manager_can_approve_student_in_assigned_branch(): void
    {
        Mail::fake();

        $center =
            $this->center(
                '33'
            );

        $branch =
            $this->branch(
                $center
            );

        $manager =
            $this->createCenterUser(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branch
        );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Student,
                branch: $branch,
                nationalId: '910000003',
                email: 'student.approval@example.test'
            );

        $this->actingAs(
            $manager
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            )
            ->callAction(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            );

        $request->refresh();

        $this->assertSame(
            RegistrationRequestStatus::Approved,
            $request->status
        );

        $person =
            Person::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'national_id_number',
                '910000003'
            )
            ->firstOrFail();

        $account =
            User::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $person->id
            )
            ->where(
                'role_id',
                $this->role(
                    SystemRole::Student
                )->id
            )
            ->firstOrFail();

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertDatabaseHas(
            'students',
            [
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $person->id,

                'user_id' =>
                $account->id,
            ]
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            fn(
                RegistrationCredentialsMail $mail
            ): bool =>
            $mail->hasTo(
                'student.approval@example.test'
            )
        );
    }

    public function test_unclassified_registration_does_not_show_approve_action(): void
    {
        $center =
            $this->center(
                '34'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            RegistrationRequest::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '910000004',

                'selected_role_id' =>
                null,

                'selected_branch_id' =>
                null,
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            );
    }

    public function test_student_without_selected_branch_does_not_show_approve_action(): void
    {
        $center =
            $this->center(
                '35'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Student,
                branch: null,
                nationalId: '910000005',
                email: 'student.no.branch@example.test'
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'approve'
                )->table(
                    $request
                )
            );
    }

    public function test_reissue_credentials_action_generates_new_password_and_sends_email(): void
    {
        Mail::fake();

        $center =
            $this->center(
                '36'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        [
            $request,
            $account,
        ] =
            $this->approvedTeacherRegistration(
                center: $center,
                nationalId: '910000006',
                email: 'teacher.reissue@example.test',
                mustChangePassword: true
            );

        $originalPassword =
            $account->password;

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'reissueCredentials'
                )->table(
                    $request
                )
            )
            ->callAction(
                TestAction::make(
                    'reissueCredentials'
                )->table(
                    $request
                )
            );

        $account->refresh();

        $this->assertNotSame(
            $originalPassword,
            $account->password
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $this->assertNull(
            $account->temporary_password_used_at
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'user_account.temporary_password_issued',

                'subject_id' =>
                $account->id,
            ]
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            fn(
                RegistrationCredentialsMail $mail
            ): bool =>
            $mail->hasTo(
                'teacher.reissue@example.test'
            )
        );
    }

    public function test_reissue_credentials_is_hidden_after_required_password_change_is_completed(): void
    {
        $center =
            $this->center(
                '37'
            );

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        [
            $request,
        ] =
            $this->approvedTeacherRegistration(
                center: $center,
                nationalId: '910000007',
                email: 'teacher.completed@example.test',
                mustChangePassword: false
            );

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ListRegistrationRequests::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'reissueCredentials'
                )->table(
                    $request
                )
            );
    }

    private function center(
        string $identifierCode
    ): Center {
        return Center::factory()
            ->create([
                'identifier_code' =>
                $identifierCode,
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->create([
                'center_id' =>
                $center->id,

                'status' =>
                BranchStatus::Active,
            ]);
    }

    private function registrationRequest(
        Center $center,
        SystemRole $role,
        string $nationalId,
        string $email,
        ?Branch $branch = null
    ): RegistrationRequest {
        return RegistrationRequest::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                $nationalId,

                'full_name' =>
                'Registration Applicant',

                'date_of_birth' =>
                '2000-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                $email,

                'phone_number' =>
                '+970599000001',

                'selected_role_id' =>
                $this->role(
                    $role
                )->id,

                'selected_branch_id' =>
                $branch?->id,

                'status' =>
                RegistrationRequestStatus::Pending,

                'pending_marker' =>
                1,
            ]);
    }

    /**
     * @return array{
     *     0: RegistrationRequest,
     *     1: User
     * }
     */
    private function approvedTeacherRegistration(
        Center $center,
        string $nationalId,
        string $email,
        bool $mustChangePassword
    ): array {
        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                $nationalId,

                'full_name' =>
                'Approved Teacher',

                'email' =>
                $email,
            ]);

        $account =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,

                'account_login_identifier' =>
                $center->identifier_code
                    . '12'
                    . '0001',

                'recovery_email' =>
                $email,

                'status' =>
                AccountStatus::Active,

                'password' =>
                Hash::make(
                    'OldTemporaryPassword!123'
                ),

                'must_change_password' =>
                $mustChangePassword,

                'temporary_password_used_at' =>
                $mustChangePassword
                    ? null
                    : now(),
            ]);

        $request =
            RegistrationRequest::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                $nationalId,

                'full_name' =>
                'Approved Teacher',

                'date_of_birth' =>
                '2000-01-01',

                'city_of_residence' =>
                'Gaza',

                'email' =>
                $email,

                'phone_number' =>
                '+970599000002',

                'selected_role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,

                'selected_branch_id' =>
                null,

                'status' =>
                RegistrationRequestStatus::Approved,

                'pending_marker' =>
                null,

                'reviewed_at' =>
                now(),
            ]);

        return [
            $request,
            $account,
        ];
    }

    public function test_approval_confirmation_reconfirms_teacher_identity_and_classification(): void
    {
        $center =
            $this->center(
                '38'
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Teacher,
                nationalId: '910000008',
                email: 'teacher.confirmation@example.test'
            );

        $description =
            RegistrationRequestResource
            ::approvalConfirmationDescription(
                $request
            )
            ->toHtml();

        $this->assertStringContainsString(
            'Registration Applicant',
            $description
        );

        $this->assertStringContainsString(
            '910000008',
            $description
        );

        $this->assertStringContainsString(
            '2000-01-01',
            $description
        );

        $this->assertStringContainsString(
            'Gaza',
            $description
        );

        $this->assertStringContainsString(
            'teacher.confirmation@example.test',
            $description
        );

        $this->assertStringContainsString(
            '+970599000001',
            $description
        );

        $this->assertStringContainsString(
            SystemRole::Teacher->label(),
            $description
        );

        $this->assertStringContainsString(
            'Not applicable',
            $description
        );

        $this->assertStringContainsString(
            'Personal Picture:',
            $description
        );

        $this->assertStringContainsString(
            'Not provided',
            $description
        );
    }

    public function test_approval_confirmation_reconfirms_student_branch(): void
    {
        $center =
            $this->center(
                '39'
            );

        $branch =
            $this->branch(
                $center
            );

        $request =
            $this->registrationRequest(
                center: $center,
                role: SystemRole::Student,
                branch: $branch,
                nationalId: '910000009',
                email: 'student.confirmation@example.test'
            );

        $request->forceFill([
            'personal_picture_path' =>
            'registration-requests/'
                . $center->id
                . '/personal-pictures/applicant.png',
        ])->save();

        $description =
            RegistrationRequestResource
            ::approvalConfirmationDescription(
                $request
            )
            ->toHtml();

        $this->assertStringContainsString(
            SystemRole::Student->label(),
            $description
        );

        $this->assertStringContainsString(
            $branch->name,
            $description
        );

        $this->assertStringNotContainsString(
            'Not applicable',
            $description
        );

        $this->assertStringContainsString(
            'View Personal Picture',
            $description
        );

        $this->assertStringContainsString(
            route(
                'admin.registration-requests.personal-picture',
                [
                    'registrationRequest' =>
                    $request->id,
                ]
            ),
            html_entity_decode(
                $description
            )
        );
    }

    private function createCenterUser(
        SystemRole $role,
        Center $center
    ): User {
        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

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
            ]);
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
