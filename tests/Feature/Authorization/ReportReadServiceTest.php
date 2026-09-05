<?php

namespace Tests\Feature\Authorization;

use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Reports\ReportReadService;
use App\Support\Enums\SystemRole;
use App\Support\Enums\CourseClassStatus;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Support\Enums\EnrollmentStatus;
use App\Services\Finance\FinanceReadService;

class ReportReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_platform_owner_receives_platform_report_scope(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            $actor->id,
            $scope['actor_id']
        );

        $this->assertSame(
            SystemRole::PlatformOwner->value,
            $scope['role']
        );

        $this->assertSame(
            'platform',
            $scope['scope_type']
        );

        $this->assertNull(
            $scope['center_id']
        );

        $this->assertNull(
            $scope['branch_id']
        );

        $this->assertNull(
            $scope['subject_id']
        );
    }

    public function test_platform_owner_requires_platform_tenant_context(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $center =
            Center::factory()
            ->active()
            ->create();

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->scope(
                $actor
            );
    }

    public function test_center_owner_receives_center_wide_report_scope(): void
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

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            'center',
            $scope['scope_type']
        );

        $this->assertSame(
            $center->id,
            $scope['center_id']
        );

        $this->assertNull(
            $scope['branch_id']
        );
    }

    public function test_center_owner_requires_center_wide_branch_context(): void
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
            ->establishBranchScope(
                $branch
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->scope(
                $actor
            );
    }

    public function test_branch_manager_requires_matching_active_assignment(): void
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

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            'branch',
            $scope['scope_type']
        );

        $this->assertSame(
            $center->id,
            $scope['center_id']
        );

        $this->assertSame(
            $branch->id,
            $scope['branch_id']
        );
    }

    public function test_branch_manager_stale_context_without_active_assignment_fails_closed(): void
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
                SystemRole::BranchManager,
                $center
            );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branch
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->scope(
                $actor
            );
    }

    public function test_finance_employee_requires_matching_active_assignment(): void
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

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            'branch',
            $scope['scope_type']
        );

        $this->assertSame(
            $branch->id,
            $scope['branch_id']
        );
    }

    public function test_teacher_receives_self_scoped_report_identity(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            'teacher',
            $scope['scope_type']
        );

        $this->assertSame(
            $center->id,
            $scope['center_id']
        );

        $this->assertSame(
            $teacher->id,
            $scope['subject_id']
        );

        $this->assertNull(
            $scope['branch_id']
        );
    }

    public function test_student_receives_self_scoped_report_identity(): void
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
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            'student',
            $scope['scope_type']
        );

        $this->assertSame(
            $student->id,
            $scope['subject_id']
        );

        $this->assertNull(
            $scope['branch_id']
        );
    }

    public function test_cross_center_report_context_is_rejected(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->tenant()
            ->establishCenterScope(
                $centerB
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->scope(
                $actor
            );
    }

    public function test_service_uses_persisted_actor_role_instead_of_tampered_memory_state(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $centerOwnerRole =
            $this->role(
                SystemRole::CenterOwner
            );

        /*
         * Tamper only with the in-memory instance.
         * The persisted User Account remains Teacher.
         */
        $actor->role_id =
            $centerOwnerRole->id;

        $actor->unsetRelation(
            'role'
        );

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $scope =
            $this->service()
            ->scope(
                $actor
            );

        $this->assertSame(
            SystemRole::Teacher->value,
            $scope['role']
        );

        $this->assertSame(
            'teacher',
            $scope['scope_type']
        );

        $this->assertSame(
            $teacher->id,
            $scope['subject_id']
        );
    }

    public function test_center_owner_enrollment_summary_is_center_scoped(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

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

        $classAActive =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classACompleted =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $studentA =
            Student::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $studentB =
            Student::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'student_id' =>
                $studentA->id,

                'class_id' =>
                $classAActive->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'student_id' =>
                $studentA->id,
                'class_id' =>
                $classACompleted->id,

                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'student_id' =>
                $studentB->id,

                'class_id' =>
                $classB->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $centerA
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $summary =
            $this->service()
            ->enrollmentSummary(
                $actor
            );

        $this->assertSame(
            2,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][EnrollmentStatus::Active->value]
        );

        $this->assertSame(
            1,
            $summary['by_status'][EnrollmentStatus::Completed->value]
        );
    }

    public function test_branch_manager_enrollment_summary_is_branch_scoped(): void
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
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branchA->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $classA->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $classB->id,

                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $summary =
            $this->service()
            ->enrollmentSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][EnrollmentStatus::Active->value]
        );

        $this->assertSame(
            0,
            $summary['by_status'][EnrollmentStatus::Completed->value]
        );
    }

    public function test_teacher_enrollment_summary_contains_only_assigned_classes(): void
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
                SystemRole::Teacher,
                $center
            );

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherTeacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $assignedClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $teacher->id,
            ]);

        $otherClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $otherTeacher->id,
            ]);

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $assignedClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $otherClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->enrollmentSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );
    }

    public function test_student_enrollment_summary_contains_only_own_enrollments(): void
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
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherStudent =
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

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $otherStudent->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->enrollmentSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );
    }

    public function test_finance_employee_cannot_read_enrollment_report(): void
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

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->enrollmentSummary(
                $actor
            );
    }

    public function test_platform_owner_enrollment_summary_is_platform_aggregate(): void
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

        $studentA =
            Student::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $studentB =
            Student::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,
            ]);

        $classA =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,
            ]);

        $classB =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'student_id' =>
                $studentA->id,

                'class_id' =>
                $classA->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'student_id' =>
                $studentB->id,

                'class_id' =>
                $classB->id,

                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]);

        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $summary =
            $this->service()
            ->enrollmentSummary(
                $actor
            );

        $this->assertSame(
            2,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][EnrollmentStatus::Active->value]
        );

        $this->assertSame(
            1,
            $summary['by_status'][EnrollmentStatus::Completed->value]
        );
    }

    public function test_center_owner_class_summary_is_center_scoped(): void
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

        CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,

                'class_status' =>
                CourseClassStatus::Completed,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->tenant()
            ->establishCenterScope(
                $centerA
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $summary =
            $this->service()
            ->classSummary(
                $actor
            );

        $this->assertSame(
            2,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Active->value]
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Completed->value]
        );
    }

    public function test_branch_manager_class_summary_is_assigned_branch_scoped(): void
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
                CourseClassStatus::Completed,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->branchContext()
            ->establishBranchScope(
                $branchA
            );

        $summary =
            $this->service()
            ->classSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Active->value]
        );

        $this->assertSame(
            0,
            $summary['by_status'][CourseClassStatus::Completed->value]
        );
    }

    public function test_teacher_class_summary_contains_only_assigned_classes(): void
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
                SystemRole::Teacher,
                $center
            );

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherTeacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $teacher->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $otherTeacher->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->classSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );
    }

    public function test_student_class_summary_contains_only_enrolled_classes(): void
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
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherStudent =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $ownClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        $otherClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'class_status' =>
                CourseClassStatus::Completed,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $ownClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $otherStudent->id,

                'class_id' =>
                $otherClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Completed,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->classSummary(
                $actor
            );

        $this->assertSame(
            1,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Active->value]
        );

        $this->assertSame(
            0,
            $summary['by_status'][CourseClassStatus::Completed->value]
        );
    }

    public function test_finance_employee_cannot_read_class_report(): void
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

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->classSummary(
                $actor
            );
    }

    public function test_platform_owner_class_summary_is_platform_aggregate(): void
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

        CourseClass::factory()
            ->create([
                'center_id' =>
                $centerA->id,

                'branch_id' =>
                $branchA->id,

                'class_status' =>
                CourseClassStatus::Active,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $centerB->id,

                'branch_id' =>
                $branchB->id,

                'class_status' =>
                CourseClassStatus::Completed,
            ]);

        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $summary =
            $this->service()
            ->classSummary(
                $actor
            );

        $this->assertSame(
            2,
            $summary['total']
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Active->value]
        );

        $this->assertSame(
            1,
            $summary['by_status'][CourseClassStatus::Completed->value]
        );
    }

    public function test_center_owner_attendance_summary_is_center_scoped(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::CenterOwner,
                $centerA
            );

        $this->tenant()
            ->establishCenterScope(
                $centerA
            );

        $this->branchContext()
            ->establishCenterWideScope();

        $summary =
            $this->service()
            ->attendanceSummary(
                $actor
            );

        $this->assertSame(
            'center',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            1,
            $summary['breakdown_count']
        );

        $this->assertSame(
            0,
            $summary['recorded_sessions']
        );

        $this->assertSame(
            '0.00',
            $summary['attendance_equivalent']
        );

        $this->assertNull(
            $summary['attendance_percentage']
        );
    }

    public function test_branch_manager_attendance_summary_uses_assigned_branch(): void
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

        $summary =
            $this->service()
            ->attendanceSummary(
                $actor
            );

        $this->assertSame(
            'branch',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            $branch->id,
            $summary['scope']['branch_id']
        );

        $this->assertSame(
            1,
            $summary['breakdown_count']
        );

        $this->assertSame(
            0,
            $summary['recorded_sessions']
        );
    }

    public function test_teacher_attendance_summary_contains_only_assigned_classes(): void
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
                SystemRole::Teacher,
                $center
            );

        $teacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherTeacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $teacher->id,
            ]);

        CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'assigned_teacher_id' =>
                $otherTeacher->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->attendanceSummary(
                $actor
            );

        $this->assertSame(
            'teacher',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            1,
            $summary['breakdown_count']
        );

        $this->assertSame(
            0,
            $summary['recorded_sessions']
        );
    }

    public function test_student_attendance_summary_contains_only_own_enrollments(): void
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
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $otherStudent =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $ownClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $otherClass =
            CourseClass::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $ownClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        Enrollment::factory()
            ->create([
                'center_id' =>
                $center->id,

                'student_id' =>
                $otherStudent->id,

                'class_id' =>
                $otherClass->id,

                'enrollment_status' =>
                EnrollmentStatus::Active,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $summary =
            $this->service()
            ->attendanceSummary(
                $actor
            );

        $this->assertSame(
            'student',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            1,
            $summary['breakdown_count']
        );

        $this->assertSame(
            0,
            $summary['recorded_sessions']
        );
    }

    public function test_finance_employee_cannot_read_attendance_report(): void
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

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->attendanceSummary(
                $actor
            );
    }

    public function test_platform_owner_cannot_read_cross_tenant_attendance_report(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->attendanceSummary(
                $actor
            );
    }

    public function test_center_owner_finance_summary_uses_operational_finance_service(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create([
                'operating_currency_code' =>
                'USD',
            ]);

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

        $expected =
            app(FinanceReadService::class)
            ->operationalSummary(
                $actor
            );

        $summary =
            $this->service()
            ->financeSummary(
                $actor
            );

        $this->assertSame(
            'center',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            'operational',
            $summary['report_type']
        );

        $this->assertSame(
            $expected,
            $summary['data']
        );
    }

    public function test_finance_employee_finance_summary_is_assigned_branch_operational_report(): void
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

        $summary =
            $this->service()
            ->financeSummary(
                $actor
            );

        $this->assertSame(
            'operational',
            $summary['report_type']
        );

        $this->assertSame(
            $branch->id,
            $summary['data']['branch_id']
        );

        $this->assertSame(
            $center->id,
            $summary['data']['center_id']
        );
    }

    public function test_student_finance_summary_uses_own_student_balance(): void
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
                SystemRole::Student,
                $center
            );

        $student =
            Student::factory()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $expected =
            app(FinanceReadService::class)
            ->studentBalance(
                $actor,
                $student
            );

        $summary =
            $this->service()
            ->financeSummary(
                $actor
            );

        $this->assertSame(
            'student',
            $summary['scope']['scope_type']
        );

        $this->assertSame(
            'student_balance',
            $summary['report_type']
        );

        $this->assertSame(
            $expected,
            $summary['data']
        );
    }

    public function test_teacher_cannot_read_financial_report(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $actor =
            $this->createUserForRole(
                SystemRole::Teacher,
                $center
            );

        Teacher::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $actor->person_id,

                'user_id' =>
                $actor->id,
            ]);

        $this->tenant()
            ->establishCenterScope(
                $center
            );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->financeSummary(
                $actor
            );
    }

    public function test_platform_owner_cannot_read_cross_tenant_financial_report(): void
    {
        $actor =
            $this->createUserForRole(
                SystemRole::PlatformOwner
            );

        $this->tenant()
            ->establishPlatformScope();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->financeSummary(
                $actor
            );
    }

    private function service(): ReportReadService
    {
        return app(
            ReportReadService::class
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
        if (
            $role
            === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' =>
                    null,

                    'person_id' =>
                    null,

                    'role_id' =>
                    $this->role(
                        $role
                    )->id,
                ]);
        }

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