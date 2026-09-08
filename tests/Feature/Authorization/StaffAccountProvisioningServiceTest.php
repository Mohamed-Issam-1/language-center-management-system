<?php

namespace Tests\Feature\Authorization;

use App\Models\BranchManager;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Staff\StaffAccountProvisioningService;
use App\Services\Staff\StaffOperationalManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StaffAccountProvisioningServiceTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_provision_and_link_teacher_account(): void
    {
        [
            $center,
            $actor,
            $person,
            $teacher,
        ] = $this->context(
            SystemRole::Teacher
        );

        $result =
            $this->service()
            ->provisionTeacher(
                $actor,
                $teacher,
                'TEACHER@EXAMPLE.TEST'
            );

        $teacher->refresh();

        $this->assertProvisionedAccount(
            $result['account'],
            $teacher->user_id,
            $center,
            $person,
            SystemRole::Teacher,
            'teacher@example.test'
        );
    }

    public function test_center_owner_can_provision_and_link_branch_manager_account_without_creating_assignment(): void
    {
        [
            $center,
            $actor,
            $person,
            $manager,
        ] = $this->context(
            SystemRole::BranchManager
        );

        $result =
            $this->service()
            ->provisionBranchManager(
                $actor,
                $manager,
                'MANAGER@EXAMPLE.TEST'
            );

        $manager->refresh();

        $this->assertProvisionedAccount(
            $result['account'],
            $manager->user_id,
            $center,
            $person,
            SystemRole::BranchManager,
            'manager@example.test'
        );

        /*
         * Assignment remains an explicit Branch
         * administration operation.
         */
        $this->assertDatabaseCount(
            'branch_manager_assignments',
            0
        );
    }

    public function test_center_owner_can_provision_and_link_finance_employee_account_without_creating_assignment(): void
    {
        [
            $center,
            $actor,
            $person,
            $financeEmployee,
        ] = $this->context(
            SystemRole::FinanceEmployee
        );

        $result =
            $this->service()
            ->provisionFinanceEmployee(
                $actor,
                $financeEmployee,
                'FINANCE@EXAMPLE.TEST'
            );

        $financeEmployee->refresh();

        $this->assertProvisionedAccount(
            $result['account'],
            $financeEmployee->user_id,
            $center,
            $person,
            SystemRole::FinanceEmployee,
            'finance@example.test'
        );

        $this->assertDatabaseCount(
            'finance_employee_assignments',
            0
        );
    }

    public function test_deactivated_staff_cannot_receive_new_account(): void
    {
        [
            $center,
            $actor,
            $person,
            $manager,
        ] = $this->context(
            SystemRole::BranchManager,
            StaffStatus::Deactivated
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->provisionBranchManager(
                $actor,
                $manager,
                'manager@example.test'
            );
    }

    public function test_staff_with_existing_link_cannot_receive_another_account(): void
    {
        [
            $center,
            $actor,
            $person,
            $financeEmployee,
        ] = $this->context(
            SystemRole::FinanceEmployee
        );

        $account =
            $this->roleAccount(
                $center,
                $person,
                SystemRole::FinanceEmployee
            );

        $financeEmployee->forceFill([
            'user_id' =>
            $account->id,
        ])->save();

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->provisionFinanceEmployee(
                $actor,
                $financeEmployee,
                'finance@example.test'
            );
    }

    public function test_invalid_recovery_email_is_rejected_before_account_creation(): void
    {
        [
            $center,
            $actor,
            $person,
            $teacher,
        ] = $this->context(
            SystemRole::Teacher
        );

        try {
            $this->service()
                ->provisionTeacher(
                    $actor,
                    $teacher,
                    'invalid-email'
                );

            $this->fail(
                'Expected invalid recovery email to be rejected.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $teacher->refresh();

        $this->assertNull(
            $teacher->user_id
        );

        $this->assertSame(
            0,
            User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'person_id',
                    $person->id
                )
                ->count()
        );
    }

    public function test_account_and_identifier_roll_back_when_operational_linkage_fails(): void
    {
        [
            $center,
            $actor,
            $person,
            $manager,
        ] = $this->context(
            SystemRole::BranchManager
        );

        $this->mock(
            StaffOperationalManagementService::class,
            function ($mock): void {
                $mock
                    ->shouldReceive(
                        'linkBranchManagerAccount'
                    )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Link failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->provisionBranchManager(
                    $actor,
                    $manager,
                    'manager@example.test'
                );

            $this->fail(
                'Expected Staff linkage failure.'
            );
        } catch (
            RuntimeException $exception
        ) {
            $this->assertSame(
                'Link failure.',
                $exception
                    ->getMessage()
            );
        }

        $manager->refresh();

        $this->assertNull(
            $manager->user_id
        );

        $this->assertSame(
            0,
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
                        SystemRole::BranchManager
                    )->id
                )
                ->count()
        );

        $this->assertDatabaseMissing(
            'account_identifier_sequences',
            [
                'center_identifier_code' =>
                '41',

                'role_code' =>
                SystemRole::BranchManager
                    ->value,
            ]
        );
    }

    private function assertProvisionedAccount(
        User $account,
        ?int $linkedUserId,
        Center $center,
        Person $person,
        SystemRole $role,
        string $recoveryEmail
    ): void {
        $account->refresh();

        $this->assertSame(
            $account->id,
            $linkedUserId
        );

        $this->assertSame(
            $center->id,
            $account->center_id
        );

        $this->assertSame(
            $person->id,
            $account->person_id
        );

        $this->assertSame(
            $role,
            $account->systemRole()
        );

        $this->assertSame(
            AccountStatus::Active,
            $account->status
        );

        $this->assertSame(
            $recoveryEmail,
            $account->recovery_email
        );

        $this->assertTrue(
            $account->must_change_password
        );

        $identifier =
            (string)
            $account
                ->account_login_identifier;

        $this->assertSame(
            8,
            strlen(
                $identifier
            )
        );

        $this->assertTrue(
            ctype_digit(
                $identifier
            )
        );
    }

    /**
     * @return array{
     *     0:Center,
     *     1:User,
     *     2:Person,
     *     3:Teacher|BranchManager|FinanceEmployee
     * }
     */
    private function context(
        SystemRole $role,
        StaffStatus $status =
        StaffStatus::Active
    ): array {
        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '41',
            ]);

        $actorPerson =
            Person::factory()
            ->for($center)
            ->create();

        $actor =
            $this->roleAccount(
                $center,
                $actorPerson,
                SystemRole::CenterOwner
            );

        $person =
            Person::factory()
            ->for($center)
            ->create([
                'national_id_number' =>
                '900000001',

                'email' =>
                'staff@example.test',
            ]);

        $attributes = [
            'center_id' =>
            $center->id,

            'person_id' =>
            $person->id,

            'user_id' =>
            null,

            'status' =>
            $status,

            'deactivated_at' =>
            $status
                === StaffStatus::Deactivated
                ? now()
                : null,
        ];

        $record =
            match ($role) {
                SystemRole::Teacher =>
                Teacher::factory()
                    ->create(
                        $attributes
                    ),

                SystemRole::BranchManager =>
                BranchManager::factory()
                    ->create(
                        $attributes
                    ),

                SystemRole::FinanceEmployee =>
                FinanceEmployee::factory()
                    ->create(
                        $attributes
                    ),

                default =>
                throw new RuntimeException(
                    'Unsupported Staff test role.'
                ),
            };

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        return [
            $center,
            $actor,
            $person,
            $record,
        ];
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
                $this->role(
                    $role
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function service(): StaffAccountProvisioningService
    {
        return app(
            StaffAccountProvisioningService::class
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