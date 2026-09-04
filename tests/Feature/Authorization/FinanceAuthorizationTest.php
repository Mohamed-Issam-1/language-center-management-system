<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FinanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_permission_matrix_matches_fixed_roles(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $finance =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        $student =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $teacher =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        foreach (
            [
                $owner,
                $manager,
                $finance,
            ] as $user
        ) {
            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ViewFinancialData
                )
            );

            $this->assertTrue(
                $user->hasPermission(
                    SystemPermission::ManageFinancialOperations
                )
            );
        }

        $this->assertTrue(
            $student->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );

        $this->assertFalse(
            $student->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        );

        $this->assertFalse(
            $teacher->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );

        $this->assertFalse(
            $platformOwner->hasPermission(
                SystemPermission::ViewFinancialData
            )
        );
    }

    public function test_center_owner_can_manage_finance_across_own_center_only(): void
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
            ->active()
            ->for($centerA)
            ->create();

        $branchA2 =
            Branch::factory()
            ->active()
            ->for($centerA)
            ->create();

        $branchB =
            Branch::factory()
            ->active()
            ->for($centerB)
            ->create();

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        [
            $enrollmentA1,
            $feeA1,
            $paymentA1,
            $studentA1,
        ] = $this->financialFixture(
            $branchA1
        );

        [
            $enrollmentA2,
            $feeA2,
            $paymentA2,
            $studentA2,
        ] = $this->financialFixture(
            $branchA2
        );

        [
            $enrollmentB,
            $feeB,
            $paymentB,
            $studentB,
        ] = $this->financialFixture(
            $branchB
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'view',
                    $feeA1
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'view',
                    $feeA2
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentA2,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'void',
                    $feeA1
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentA1,
                        $branchA1,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'reverse',
                    $paymentA2
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'view',
                    $feeB
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'reverse',
                    $paymentB
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentB,
                        $branchB,
                    ]
                )
        );
    }

    public function test_branch_manager_can_manage_only_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $branchB =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        [
            $enrollmentA,
            $feeA,
            $paymentA,
            $studentA,
        ] = $this->financialFixture(
            $branchA
        );

        [
            $enrollmentB,
            $feeB,
            $paymentB,
            $studentB,
        ] = $this->financialFixture(
            $branchB
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $feeA
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentA,
                        $branchA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($manager)
                ->allows(
                    'reverse',
                    $paymentA
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'view',
                    $feeB
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentB,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'reverse',
                    $paymentB
                )
        );
    }

    public function test_finance_employee_can_manage_only_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $branchB =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $finance =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $branchA
        );

        [
            $enrollmentA,
            $feeA,
            $paymentA,
            $studentA,
        ] = $this->financialFixture(
            $branchA
        );

        [
            $enrollmentB,
            $feeB,
            $paymentB,
            $studentB,
        ] = $this->financialFixture(
            $branchB
        );

        $this->assertTrue(
            Gate::forUser($finance)
                ->allows(
                    'view',
                    $feeA
                )
        );

        $this->assertTrue(
            Gate::forUser($finance)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($finance)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentA,
                        $branchA,
                    ]
                )
        );

        $this->assertTrue(
            Gate::forUser($finance)
                ->allows(
                    'reverse',
                    $paymentA
                )
        );

        $this->assertFalse(
            Gate::forUser($finance)
                ->allows(
                    'view',
                    $feeB
                )
        );

        $this->assertFalse(
            Gate::forUser($finance)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $enrollmentB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($finance)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $studentB,
                        $branchB,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($finance)
                ->allows(
                    'reverse',
                    $paymentB
                )
        );
    }

    public function test_student_can_view_only_own_financial_records(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $studentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        $otherStudentUser =
            $this->createUserForRole(
                SystemRole::Student,
                $center
            );

        [
            $ownEnrollment,
            $ownFee,
            $ownPayment,
            $ownStudent,
        ] = $this->financialFixture(
            $branch,
            $studentUser
        );

        [
            $otherEnrollment,
            $otherFee,
            $otherPayment,
            $otherStudent,
        ] = $this->financialFixture(
            $branch,
            $otherStudentUser
        );

        $this->assertTrue(
            Gate::forUser($studentUser)
                ->allows(
                    'view',
                    $ownFee
                )
        );

        $this->assertTrue(
            Gate::forUser($studentUser)
                ->allows(
                    'view',
                    $ownPayment
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'view',
                    $otherFee
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'view',
                    $otherPayment
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'create',
                    [
                        EnrollmentFee::class,
                        $ownEnrollment,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'create',
                    [
                        Payment::class,
                        $ownStudent,
                        $branch,
                    ]
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'void',
                    $ownFee
                )
        );

        $this->assertFalse(
            Gate::forUser($studentUser)
                ->allows(
                    'reverse',
                    $ownPayment
                )
        );
    }

    public function test_teacher_and_platform_owner_cannot_access_center_finance(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->active()
            ->for($center)
            ->create();

        $teacher =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $platformOwner =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        [
            $enrollment,
            $fee,
            $payment,
            $student,
        ] = $this->financialFixture(
            $branch
        );

        foreach (
            [
                $teacher,
                $platformOwner,
            ] as $user
        ) {
            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $fee
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'view',
                        $payment
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            EnrollmentFee::class,
                            $enrollment,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'create',
                        [
                            Payment::class,
                            $student,
                            $branch,
                        ]
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'void',
                        $fee
                    )
            );

            $this->assertFalse(
                Gate::forUser($user)
                    ->allows(
                        'reverse',
                        $payment
                    )
            );
        }
    }

    /**
     * @return array{
     *     0: Enrollment,
     *     1: EnrollmentFee,
     *     2: Payment,
     *     3: Student
     * }
     */
    private function financialFixture(
        Branch $branch,
        ?User $studentUser = null
    ): array {
        $studentAttributes = [];

        if ($studentUser !== null) {
            $studentAttributes = [
                /*
                * Student and User must reference the same
                * Person inside the same Center.
                */
                'person_id' =>
                $studentUser->person_id,

                'user_id' =>
                $studentUser->id,
            ];
        }

        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create(
                $studentAttributes
            );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create();

        $fee =
            EnrollmentFee::factory()
            ->forEnrollment($enrollment)
            ->create();

        $payment =
            Payment::factory()
            ->forBranch($branch)
            ->forStudent($student)
            ->posted()
            ->create();

        return [
            $enrollment,
            $fee,
            $payment,
            $student,
        ];
    }

    private function assignBranchManager(
        User $user,
        Branch $branch
    ): void {
        DB::table(
            'branch_manager_assignments'
        )->insert([
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

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);
    }

    private function assignFinanceEmployee(
        User $user,
        Branch $branch
    ): void {
        FinanceEmployeeAssignment::query()
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

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this->role($role)->id,

                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        if ($center === null) {
            $center =
                Center::factory()
                ->active()
                ->create();
        }

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
                $this->role($role)->id,

                'status' =>
                AccountStatus::Active,
            ]);
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
}