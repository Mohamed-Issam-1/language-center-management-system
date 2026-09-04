<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EnrollmentFees\Pages\ListEnrollmentFees;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Course;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentFeeCreateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_creates_default_fee_through_filament_action(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
            $branch,
            '450.00',
            '2026-09-10'
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

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'createFee'
                )
            )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $fee =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $center->id,
            $fee->center_id
        );

        $this->assertSame(
            $branch->id,
            $fee->branch_id
        );

        $this->assertSame(
            $enrollment->id,
            $fee->enrollment_id
        );

        $this->assertSame(
            '450.00',
            $fee->amount
        );

        $this->assertSame(
            'USD',
            $fee->currency_code
        );

        $this->assertSame(
            EnrollmentFeeStatus::Active,
            $fee->status
        );

        $this->assertSame(
            $owner->id,
            $fee->created_by_user_id
        );

        $installments =
            $fee
            ->installments()
            ->orderBy(
                'sequence_number'
            )
            ->get();

        $this->assertCount(
            1,
            $installments
        );

        $this->assertSame(
            1,
            $installments[0]
                ->sequence_number
        );

        $this->assertSame(
            '450.00',
            $installments[0]
                ->amount
        );

        $this->assertSame(
            '2026-09-10',
            $installments[0]
                ->due_date
                ->toDateString()
        );

        $this->assertDatabaseHas(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_created',

                'subject_type' =>
                'enrollment_fees',

                'subject_id' =>
                $fee->id,
            ]
        );
    }

    public function test_center_owner_creates_custom_fee_with_multiple_installments(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
            $branch,
            '500.00'
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

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    '600.00',

                    'installments' => [
                        [
                            'amount' =>
                            '100.00',

                            'due_date' =>
                            '2026-10-01',
                        ],
                        [
                            'amount' =>
                            '200.00',

                            'due_date' =>
                            '2026-11-01',
                        ],
                        [
                            'amount' =>
                            '300.00',

                            'due_date' =>
                            '2026-12-01',
                        ],
                    ],
                ]
            );

        $fee =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            '600.00',
            $fee->amount
        );

        $installments =
            $fee
            ->installments()
            ->orderBy(
                'sequence_number'
            )
            ->get();

        $this->assertCount(
            3,
            $installments
        );

        $this->assertSame(
            [
                1,
                2,
                3,
            ],
            $installments
                ->pluck(
                    'sequence_number'
                )
                ->all()
        );

        $this->assertSame(
            [
                '100.00',
                '200.00',
                '300.00',
            ],
            $installments
                ->pluck(
                    'amount'
                )
                ->all()
        );

        $this->assertSame(
            [
                '2026-10-01',
                '2026-11-01',
                '2026-12-01',
            ],
            $installments
                ->map(
                    fn(
                        $installment
                    ): string =>
                    $installment
                        ->due_date
                        ->toDateString()
                )
                ->all()
        );
    }

    public function test_branch_manager_can_create_fee_inside_assigned_branch(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
            $branch
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

        $this->actingAs(
            $manager
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $fee =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $fee->branch_id
        );

        $this->assertSame(
            $manager->id,
            $fee->created_by_user_id
        );
    }

    public function test_finance_employee_can_create_fee_inside_assigned_branch(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
            $branch
        );

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

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

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $fee =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $branch->id,
            $fee->branch_id
        );

        $this->assertSame(
            $finance->id,
            $fee->created_by_user_id
        );
    }

    public function test_fee_creation_uses_historical_enrollment_branch_after_student_moves(): void
    {
        $center =
            $this->center();

        $financialBranch =
            $this->branch(
                $center
            );

        $newStudentBranch =
            $this->branch(
                $center
            );

        [
            $enrollment,
            $student,
        ] = $this->createEnrollment(
            $financialBranch
        );

        $student->forceFill([
            'branch_id' =>
            $newStudentBranch->id,
        ])->save();

        $finance =
            $this->createCenterUser(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $financialBranch
        );

        $this->actingAs(
            $finance
        );

        $this->establishBranchContext(
            $center,
            $financialBranch
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $fee =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            $financialBranch->id,
            $fee->branch_id
        );

        $this->assertNotSame(
            $student->branch_id,
            $fee->branch_id
        );
    }

    public function test_branch_manager_cannot_inject_enrollment_from_other_branch(): void
    {
        $center =
            $this->center();

        $ownBranch =
            $this->branch(
                $center
            );

        $otherBranch =
            $this->branch(
                $center
            );

        [
            $otherEnrollment,
        ] = $this->createEnrollment(
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

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $otherEnrollment->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $this->assertDatabaseMissing(
            'enrollment_fees',
            [
                'enrollment_id' =>
                $otherEnrollment->id,
            ]
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_created',
            ]
        );
    }

    public function test_center_owner_cannot_inject_cross_center_enrollment(): void
    {
        $centerA =
            $this->center();

        $centerB =
            $this->center();

        $branchB =
            $this->branch(
                $centerB
            );

        [
            $enrollmentB,
        ] = $this->createEnrollment(
            $branchB
        );

        $ownerA =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->actingAs(
            $ownerA
        );

        $this->establishCenterOwnerContext(
            $centerA
        );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollmentB->id,

                    'amount' =>
                    null,

                    'installments' =>
                    [],
                ]
            );

        $this->assertDatabaseMissing(
            'enrollment_fees',
            [
                'enrollment_id' =>
                $enrollmentB->id,
            ]
        );
    }

    public function test_duplicate_fee_for_same_enrollment_is_not_created_twice(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
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

        $payload = [
            'enrollment_id' =>
            $enrollment->id,

            'amount' =>
            null,

            'installments' =>
            [],
        ];

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                $payload
            );

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                $payload
            );

        $this->assertSame(
            1,
            DB::table(
                'enrollment_fees'
            )
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'enrollment_id',
                    $enrollment->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            DB::table(
                'audit_records'
            )
                ->where(
                    'action_type',
                    'finance.fee_created'
                )
                ->where(
                    'subject_type',
                    'enrollment_fees'
                )
                ->count()
        );
    }

    public function test_installment_total_must_equal_fee_amount(): void
    {
        $center =
            $this->center();

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->createEnrollment(
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

        Livewire::test(
            ListEnrollmentFees::class
        )
            ->callAction(
                TestAction::make(
                    'createFee'
                ),
                [
                    'enrollment_id' =>
                    $enrollment->id,

                    'amount' =>
                    '300.00',

                    'installments' => [
                        [
                            'amount' =>
                            '100.00',

                            'due_date' =>
                            '2026-10-01',
                        ],
                        [
                            'amount' =>
                            '100.00',

                            'due_date' =>
                            '2026-11-01',
                        ],
                    ],
                ]
            );

        $this->assertDatabaseMissing(
            'enrollment_fees',
            [
                'enrollment_id' =>
                $enrollment->id,
            ]
        );

        $this->assertDatabaseCount(
            'fee_installments',
            0
        );

        $this->assertDatabaseMissing(
            'audit_records',
            [
                'action_type' =>
                'finance.fee_created',
            ]
        );
    }

    private function center(): Center
    {
        return Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->for(
                $center
            )
            ->active()
            ->create();
    }

    /**
     * @return array{0: Enrollment, 1: Student, 2: Course}
     */
    private function createEnrollment(
        Branch $branch,
        string $defaultFee = '250.00',
        string $enrollmentDate = '2026-09-04'
    ): array {
        $student =
            Student::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create();

        $course =
            Course::factory()
            ->create([
                'center_id' =>
                $branch->center_id,

                'default_fee' =>
                $defaultFee,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->active()
            ->create([
                'course_id' =>
                $course->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent(
                $student
            )
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'enrollment_date' =>
                $enrollmentDate,
            ]);

        return [
            $enrollment,
            $student,
            $course,
        ];
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