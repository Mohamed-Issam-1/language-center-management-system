<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
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
use App\Services\Audit\AuditRecorder;
use App\Services\Finance\FinanceManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FinanceManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_owner_creates_fee_from_course_default_with_single_installment(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
            $course,
        ] = $this->enrollmentFixture(
            $branch,
            '450.00',
            '2026-09-10'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $fee =
            $this->service()
            ->createFee(
                $owner,
                $enrollment
            );

        $fee->refresh();

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

        $this->assertNull(
            $fee->voided_by_user_id
        );

        $this->assertNull(
            $fee->voided_at
        );

        $this->assertNull(
            $fee->void_reason
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
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'actor_user_id' =>
                $owner->id,

                'action_type' =>
                'finance.fee_created',

                'subject_type' =>
                'enrollment_fees',

                'subject_id' =>
                $fee->id,
            ]
        );

        /*
         * Financial values are historical snapshots.
         */
        $course->forceFill([
            'default_fee' =>
            '999.00',
        ])->save();

        $center->forceFill([
            'operating_currency_code' =>
            'EUR',
        ])->save();

        $fee->refresh();

        $this->assertSame(
            '450.00',
            $fee->amount
        );

        $this->assertSame(
            'USD',
            $fee->currency_code
        );
    }

    public function test_custom_fee_creates_multiple_installments_with_backend_sequences(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch,
            '500.00'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $fee =
            $this->service()
            ->createFee(
                $owner,
                $enrollment,
                [
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
                    fn($installment): string =>
                    $installment
                        ->due_date
                        ->toDateString()
                )
                ->all()
        );
    }

    public function test_installment_amounts_must_equal_fee_amount_and_operation_rolls_back(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        try {
            $this->service()
                ->createFee(
                    $owner,
                    $enrollment,
                    [
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

            $this->fail(
                'Expected installment total validation to fail.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Installment amounts must equal the total Fee amount.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'enrollment_fees',
            [
                'enrollment_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_duplicate_fee_for_same_enrollment_is_rejected(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment
            );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'This Enrollment already has a Fee.'
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment
            );
    }

    public function test_fee_creation_rejects_unsupported_input_fields(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Fee creation received unsupported fields: center_id.'
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment,
                [
                    'center_id' =>
                    999,
                ]
            );
    }

    public function test_installment_rejects_backend_controlled_sequence_number(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'Installment received unsupported fields: sequence_number.'
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment,
                [
                    'amount' =>
                    '100.00',

                    'installments' => [
                        [
                            'amount' =>
                            '100.00',

                            'due_date' =>
                            '2026-10-01',

                            'sequence_number' =>
                            99,
                        ],
                    ],
                ]
            );
    }

    public function test_fee_requires_center_operating_currency(): void
    {
        $center =
            $this->center(
                null
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Center operating currency must be configured before creating Fees.'
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment
            );
    }

    public function test_cross_center_enrollment_is_rejected_before_fee_creation(): void
    {
        $centerA =
            $this->center(
                'USD'
            );

        $centerB =
            $this->center(
                'USD'
            );

        $branchB =
            $this->branch(
                $centerB
            );

        [
            $enrollmentB,
        ] = $this->enrollmentFixture(
            $branchB
        );

        $ownerA =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->establishCenterOwnerContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createFee(
                $ownerA,
                $enrollmentB
            );
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'Center Owner financial operations require center-wide Branch context.'
        );

        $this->service()
            ->createFee(
                $owner,
                $enrollment
            );
    }

    public function test_branch_manager_can_create_fee_only_in_assigned_branch(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        [
            $enrollmentA,
        ] = $this->enrollmentFixture(
            $branchA
        );

        [
            $enrollmentB,
        ] = $this->enrollmentFixture(
            $branchB
        );

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignBranchManager(
            $manager,
            $branchA
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $fee =
            $this->service()
            ->createFee(
                $manager,
                $enrollmentA
            );

        $this->assertSame(
            $branchA->id,
            $fee->branch_id
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createFee(
                $manager,
                $enrollmentB
            );
    }

    public function test_finance_employee_can_create_fee_only_in_assigned_branch(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branchA =
            $this->branch(
                $center
            );

        $branchB =
            $this->branch(
                $center
            );

        [
            $enrollmentA,
        ] = $this->enrollmentFixture(
            $branchA
        );

        [
            $enrollmentB,
        ] = $this->enrollmentFixture(
            $branchB
        );

        $finance =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        $this->assignFinanceEmployee(
            $finance,
            $branchA
        );

        $this->establishBranchContext(
            $center,
            $branchA
        );

        $fee =
            $this->service()
            ->createFee(
                $finance,
                $enrollmentA
            );

        $this->assertSame(
            $branchA->id,
            $fee->branch_id
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createFee(
                $finance,
                $enrollmentB
            );
    }

    public function test_service_uses_persisted_enrollment_instead_of_tampered_model_state(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch,
            '325.00'
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        /*
         * Mutate only the in-memory object.
         * Persisted ownership must remain authoritative.
         */
        $enrollment->center_id =
            999999;

        $enrollment->class_id =
            999999;

        $fee =
            $this->service()
            ->createFee(
                $owner,
                $enrollment
            );

        $persistedEnrollment =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $enrollment->id
            );

        $persistedClass =
            CourseClass::withoutGlobalScopes()
            ->findOrFail(
                $persistedEnrollment->class_id
            );

        $this->assertSame(
            $center->id,
            $fee->center_id
        );

        $this->assertSame(
            $persistedClass->branch_id,
            $fee->branch_id
        );

        $this->assertSame(
            $persistedEnrollment->id,
            $fee->enrollment_id
        );
    }

    public function test_invalid_monetary_values_are_rejected(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        foreach (
            [
                '0',
                '0.00',
                '12.345',
                '-10.00',
                'abc',
            ] as $amount
        ) {
            try {
                $this->service()
                    ->createFee(
                        $owner,
                        $enrollment,
                        [
                            'amount' =>
                            $amount,
                        ]
                    );

                $this->fail(
                    "Invalid Fee amount [{$amount}] was accepted."
                );
            } catch (DomainException) {
                $this->assertDatabaseMissing(
                    'enrollment_fees',
                    [
                        'enrollment_id' =>
                        $enrollment->id,
                    ]
                );
            }
        }
    }

    public function test_audit_failure_rolls_back_fee_and_installments(): void
    {
        $center =
            $this->center(
                'USD'
            );

        $branch =
            $this->branch(
                $center
            );

        [
            $enrollment,
        ] = $this->enrollmentFixture(
            $branch
        );

        $owner =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->establishCenterOwnerContext(
            $center
        );

        $this->mock(
            AuditRecorder::class,
            function ($mock): void {
                $mock
                    ->shouldReceive(
                        'record'
                    )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure.'
                        )
                    );
            }
        );

        try {
            $this->service()
                ->createFee(
                    $owner,
                    $enrollment,
                    [
                        'amount' =>
                        '300.00',

                        'installments' => [
                            [
                                'amount' =>
                                '150.00',

                                'due_date' =>
                                '2026-10-01',
                            ],
                            [
                                'amount' =>
                                '150.00',

                                'due_date' =>
                                '2026-11-01',
                            ],
                        ],
                    ]
                );

            $this->fail(
                'Expected audit failure.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'enrollment_fees',
            [
                'enrollment_id' =>
                $enrollment->id,
            ]
        );

        $this->assertSame(
            0,
            DB::table(
                'fee_installments'
            )
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );
    }

    private function service(): FinanceManagementService
    {
        return app(
            FinanceManagementService::class
        );
    }

    private function center(
        ?string $currencyCode
    ): Center {
        return Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                $currencyCode,
            ]);
    }

    private function branch(
        Center $center
    ): Branch {
        return Branch::factory()
            ->active()
            ->for($center)
            ->create();
    }

    /**
     * @return array{
     *     0: Enrollment,
     *     1: Course,
     *     2: CourseClass,
     *     3: Student
     * }
     */
    private function enrollmentFixture(
        Branch $branch,
        string $defaultFee = '300.00',
        string $enrollmentDate = '2026-09-03'
    ): array {
        $center =
            Center::query()
            ->findOrFail(
                $branch->center_id
            );

        $course =
            Course::factory()
            ->for($center)
            ->create([
                'default_fee' =>
                $defaultFee,
            ]);

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->create([
                'course_id' =>
                $course->id,
            ]);

        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
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
            $course,
            $courseClass,
            $student,
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
            $role ===
            SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this
                        ->role($role)
                        ->id,

                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        if ($center === null) {
            $center =
                $this->center(
                    'USD'
                );
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
                $this
                    ->role($role)
                    ->id,

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
