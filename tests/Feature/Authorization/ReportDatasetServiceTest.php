<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\Enrollment;
use App\Models\Student;
use App\Support\Enums\EnrollmentStatus;
use App\Services\Reports\ReportDatasetService;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Support\Enums\EnrollmentFeeStatus;

class ReportDatasetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_academic_report_returns_row_level_dataset_filtered_by_status(): void
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

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $activeClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'class_status' =>
                CourseClassStatus::Completed,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'status' =>
                    ' ACTIVE ',
                ]
            );

        $this->assertSame(
            ReportDatasetService::ACADEMIC,
            $dataset['report_type']
        );

        $this->assertSame(
            'active',
            $dataset['filters']['status']
        );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertFalse(
            $dataset['empty']
        );

        $this->assertSame(
            $activeClass->id,
            $dataset['rows'][0]['class_id']
        );

        $this->assertSame(
            $branch->id,
            $dataset['rows'][0]['branch_id']
        );

        $this->assertSame(
            'active',
            $dataset['rows'][0]['status']
        );
    }

    public function test_status_filter_with_no_matching_records_returns_empty_dataset(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'status' =>
                    'active',
                ]
            );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            0,
            $dataset['row_count']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );
    }

    public function test_branch_manager_academic_dataset_remains_branch_scoped(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC
            );

        $this->assertSame(
            $branchA->id,
            $dataset['scope']['branch_id']
        );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertSame(
            $classA->id,
            $dataset['rows'][0]['class_id']
        );

        $this->assertSame(
            $branchA->id,
            $dataset['rows'][0]['branch_id']
        );
    }

    public function test_finance_employee_can_build_financial_dataset_only(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::FinanceEmployee,
                $center
            );

        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branch->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        $financial =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::FINANCIAL,
                [
                    'currency' =>
                    'usd',
                ]
            );

        $this->assertSame(
            'USD',
            $financial['filters']['currency']
        );

        $this->assertTrue(
            $financial['empty']
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC
            );
    }

    public function test_empty_attendance_dataset_has_explicit_empty_state(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ATTENDANCE
            );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );

        $this->assertArrayHasKey(
            'attendance_percentage',
            $dataset['columns']
        );
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'status' =>
                    'not-a-real-status',
                ]
            );
    }

    public function test_unknown_filter_is_rejected_instead_of_being_silently_ignored(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'student_id' =>
                    999999,
                ]
            );
    }

    public function test_unknown_report_type_is_rejected(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()
            ->build(
                $actor,
                'unknown-report'
            );
    }

    public function test_enrollment_report_returns_row_level_dataset(): void
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

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $student =
            Student::factory()
            ->for($center)
            ->create();

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_date' =>
                '2026-08-15',

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ENROLLMENT
            );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertFalse(
            $dataset['empty']
        );

        $this->assertSame(
            $enrollment->id,
            $dataset['rows'][0]['enrollment_id']
        );

        $this->assertSame(
            $student->id,
            $dataset['rows'][0]['student_id']
        );

        $this->assertSame(
            $courseClass->id,
            $dataset['rows'][0]['class_id']
        );

        $this->assertSame(
            $branch->id,
            $dataset['rows'][0]['branch_id']
        );

        $this->assertSame(
            'active',
            $dataset['rows'][0]['status']
        );

        $this->assertSame(
            '2026-08-15',
            $dataset['rows'][0]['enrollment_date']
        );
    }

    public function test_enrollment_report_applies_reusable_filters(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $studentA =
            Student::factory()
            ->for($center)
            ->create();

        $studentB =
            Student::factory()
            ->for($center)
            ->create();

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $matching =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $studentA->id,

                'class_id' =>
                $classA->id,

                'enrollment_date' =>
                '2026-08-15',

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $studentB->id,

                'class_id' =>
                $classB->id,

                'enrollment_date' =>
                '2026-07-15',

                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ENROLLMENT,
                [
                    'date_from' =>
                    '2026-08-01',

                    'date_to' =>
                    '2026-08-31',

                    'branch_id' =>
                    $branchA->id,

                    'class_id' =>
                    $classA->id,

                    'student_id' =>
                    $studentA->id,

                    'status' =>
                    ' ACTIVE ',
                ]
            );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertSame(
            $matching->id,
            $dataset['rows'][0]['enrollment_id']
        );

        $this->assertSame(
            [
                'date_from' =>
                '2026-08-01',

                'date_to' =>
                '2026-08-31',

                'branch_id' =>
                $branchA->id,

                'class_id' =>
                $classA->id,

                'student_id' =>
                $studentA->id,

                'status' =>
                'active',
            ],
            $dataset['filters']
        );
    }

    public function test_branch_manager_enrollment_filter_cannot_escape_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $student =
            Student::factory()
            ->for($center)
            ->create();

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $classB->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ENROLLMENT,
                [
                    'branch_id' =>
                    $branchB->id,
                ]
            );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );
    }

    public function test_academic_report_can_filter_by_course(): void
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

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'course_id' =>
                    $courseClass->course_id,
                ]
            );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertSame(
            $courseClass->id,
            $dataset['rows'][0]['class_id']
        );

        $emptyDataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'course_id' =>
                    999999,
                ]
            );

        $this->assertTrue(
            $emptyDataset['empty']
        );
    }

    public function test_branch_manager_academic_filter_cannot_escape_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ACADEMIC,
                [
                    'branch_id' =>
                    $branchB->id,
                ]
            );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );
    }

    public function test_attendance_report_returns_one_row_per_scoped_enrollment(): void
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

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $student =
            Student::factory()
            ->for($center)
            ->create();

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ATTENDANCE
            );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertFalse(
            $dataset['empty']
        );

        $this->assertSame(
            $enrollment->id,
            $dataset['rows'][0]['enrollment_id']
        );

        $this->assertSame(
            $student->id,
            $dataset['rows'][0]['student_id']
        );

        $this->assertSame(
            $courseClass->id,
            $dataset['rows'][0]['class_id']
        );

        $this->assertSame(
            $branch->id,
            $dataset['rows'][0]['branch_id']
        );

        $this->assertSame(
            $courseClass->course_id,
            $dataset['rows'][0]['course_id']
        );

        $this->assertSame(
            0,
            $dataset['rows'][0]['recorded_sessions']
        );

        $this->assertSame(
            '0.00',
            $dataset['rows'][0]['attendance_equivalent']
        );

        $this->assertSame(
            '0.00',
            $dataset['rows'][0]['absence_equivalent']
        );

        $this->assertNull(
            $dataset['rows'][0]['attendance_percentage']
        );

        $this->assertNull(
            $dataset['rows'][0]['absence_percentage']
        );
    }

    public function test_attendance_report_applies_scoped_enrollment_filters(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $studentA =
            Student::factory()
            ->for($center)
            ->create();

        $studentB =
            Student::factory()
            ->for($center)
            ->create();

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $matching =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $studentA->id,

                'class_id' =>
                $classA->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $studentB->id,

                'class_id' =>
                $classB->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ATTENDANCE,
                [
                    'branch_id' =>
                    $branchA->id,

                    'course_id' =>
                    $classA->course_id,

                    'class_id' =>
                    $classA->id,

                    'student_id' =>
                    $studentA->id,
                ]
            );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertSame(
            $matching->id,
            $dataset['rows'][0]['enrollment_id']
        );

        $this->assertSame(
            [
                'branch_id' =>
                $branchA->id,

                'course_id' =>
                $classA->course_id,

                'class_id' =>
                $classA->id,

                'student_id' =>
                $studentA->id,
            ],
            $dataset['filters']
        );
    }

    public function test_branch_manager_attendance_filter_cannot_escape_assigned_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $student =
            Student::factory()
            ->for($center)
            ->create();

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $classB->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::ATTENDANCE,
                [
                    'branch_id' =>
                    $branchB->id,
                ]
            );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );
    }

    public function test_attendance_report_rejects_date_filter_until_period_calculation_is_supported(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()
            ->build(
                $actor,
                ReportDatasetService::ATTENDANCE,
                [
                    'date_from' =>
                    '2026-08-01',
                ]
            );
    }

    public function test_center_owner_financial_report_returns_authoritative_currency_row(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->createFinancialObligation(
            $center,
            $branch,
            $actor,
            100
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::FINANCIAL,
                [
                    'currency' =>
                    'usd',
                ]
            );

        $this->assertSame(
            'USD',
            $dataset['filters']['currency']
        );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertFalse(
            $dataset['empty']
        );

        $this->assertSame(
            'USD',
            $dataset['rows'][0]['currency']
        );

        $this->assertSame(
            1,
            $dataset['rows'][0]['active_fee_count']
        );

        $this->assertSame(
            '100.00',
            $dataset['rows'][0]['active_fee_amount']
        );

        $this->assertSame(
            '0.00',
            $dataset['rows'][0]['allocated_amount']
        );

        $this->assertSame(
            '100.00',
            $dataset['rows'][0]['outstanding_amount']
        );

        $this->assertSame(
            0,
            $dataset['rows'][0]['posted_payment_count']
        );

        $this->assertSame(
            '0.00',
            $dataset['rows'][0]['posted_payment_amount']
        );

        $this->assertSame(
            0,
            $dataset['rows'][0]['reversed_payment_count']
        );

        $this->assertSame(
            '0.00',
            $dataset['rows'][0]['reversed_payment_amount']
        );
    }

    public function test_financial_report_currency_filter_can_return_explicit_empty_dataset(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->createFinancialObligation(
            $center,
            $branch,
            $actor,
            100
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::FINANCIAL,
                [
                    'currency' =>
                    'eur',
                ]
            );

        $this->assertSame(
            'EUR',
            $dataset['filters']['currency']
        );

        $this->assertSame(
            0,
            $dataset['row_count']
        );

        $this->assertTrue(
            $dataset['empty']
        );

        $this->assertSame(
            [],
            $dataset['rows']
        );
    }

    public function test_branch_manager_financial_dataset_remains_assigned_branch_scoped(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $actor->id,

                'branch_id' =>
                $branchA->id,

                'active_marker' =>
                1,

                'ended_at' =>
                null,
            ]);

        $this->createFinancialObligation(
            $center,
            $branchA,
            $actor,
            80
        );

        $this->createFinancialObligation(
            $center,
            $branchB,
            $actor,
            120
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $dataset =
            $this->service()
            ->build(
                $actor,
                ReportDatasetService::FINANCIAL
            );

        $this->assertSame(
            $branchA->id,
            $dataset['scope']['branch_id']
        );

        $this->assertSame(
            1,
            $dataset['row_count']
        );

        $this->assertSame(
            'USD',
            $dataset['rows'][0]['currency']
        );

        $this->assertSame(
            1,
            $dataset['rows'][0]['active_fee_count']
        );

        $this->assertSame(
            '80.00',
            $dataset['rows'][0]['active_fee_amount']
        );

        $this->assertSame(
            '80.00',
            $dataset['rows'][0]['outstanding_amount']
        );
    }

    public function test_financial_report_rejects_branch_filter(): void
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

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()
            ->build(
                $actor,
                ReportDatasetService::FINANCIAL,
                [
                    'branch_id' =>
                    $branch->id,
                ]
            );
    }

    /**
     * @return array{Student, FeeInstallment}
     */
    private function createFinancialObligation(
        Center $center,
        Branch $branch,
        User $creator,
        float $amount
    ): array {
        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $courseClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,
            ]);

        $fee =
            EnrollmentFee::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'enrollment_id' =>
                $enrollment->id,

                'amount' =>
                $amount,

                'currency_code' =>
                $center->operating_currency_code,

                'status' =>
                EnrollmentFeeStatus::Active,

                'created_by_user_id' =>
                $creator->id,
            ]);

        $installment =
            FeeInstallment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'enrollment_fee_id' =>
                $fee->id,

                'sequence_number' =>
                1,

                'amount' =>
                $amount,
            ]);

        return [
            $student,
            $installment,
        ];
    }

    private function service(): ReportDatasetService
    {
        return app(
            ReportDatasetService::class
        );
    }

    private function tenant(): TenantContext
    {
        return app(
            TenantContext::class
        );
    }

    private function branchContext(): BranchContext
    {
        return app(
            BranchContext::class
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

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        $center ??=
            Center::factory()
            ->active()
            ->create();

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
}
