<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Models\BranchManagerAssignment;
use App\Services\Audit\AuditRecorder;
use App\Services\Enrollment\EnrollmentManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Tests\TestCase;
use DomainException;

class EnrollmentManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_enroll_active_student_in_planned_class(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $enrollmentDate =
            now()->toDateString();

        $enrollment = $this->service()
            ->enroll(
                $owner,
                $student,
                $courseClass,
                [
                    /*
                     * Protected domain fields must not be
                     * controlled by caller input.
                     */
                    'center_id' => 999999,
                    'student_id' => 999999,
                    'class_id' => 999999,

                    'enrollment_status' =>
                    EnrollmentStatus::Cancelled,

                    'eligibility_status' =>
                    'caller-controlled-value',

                    'enrollment_number' =>
                    ' ENR-TEST-001 ',

                    'enrollment_date' =>
                    $enrollmentDate,
                ]
            );

        $this->assertSame(
            $center->id,
            $enrollment->center_id
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            $courseClass->id,
            $enrollment->class_id
        );

        /*
         * Business identifier is trimmed by the service.
         */
        $this->assertSame(
            'ENR-TEST-001',
            $enrollment->enrollment_number
        );

        $this->assertSame(
            $enrollmentDate,
            $enrollment
                ->enrollment_date
                ->toDateString()
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertSame(
            'eligible',
            $enrollment->eligibility_status
        );

        $this->assertNull(
            $enrollment->withdrawal_date
        );

        $this->assertNull(
            $enrollment->withdrawal_reason
        );

        $this->assertDatabaseHas(
            'enrollments',
            [
                'id' =>
                $enrollment->id,

                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_number' =>
                'ENR-TEST-001',

                'enrollment_status' =>
                EnrollmentStatus::Active->value,

                'eligibility_status' =>
                'eligible',
            ]
        );

        $history =
            EnrollmentHistory::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'enrollment_id',
                $enrollment->id
            )
            ->firstOrFail();

        $this->assertNull(
            $history->from_class_id
        );

        $this->assertSame(
            $courseClass->id,
            $history->to_class_id
        );

        $this->assertSame(
            $owner->id,
            $history->performed_by_user_id
        );

        $this->assertSame(
            'created',
            $history->event_type
        );

        $this->assertNull(
            $history->previous_status
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $history->new_status
        );

        $this->assertNull(
            $history->notes
        );

        $this->assertNotNull(
            $history->occurred_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $enrollment
            )
        );
    }

    public function test_enrollment_creation_rolls_back_when_audit_recording_fails(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        /*
         * Resolve the service only after replacing
         * AuditRecorder in the container.
         */
        $this->bindFailingAudit();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-ROLLBACK-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected audit failure to abort Enrollment creation.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        /*
         * Enrollment, domain History, and Audit are part
         * of one transaction.
         *
         * A failed Audit operation must leave no successful
         * Enrollment operation behind.
         */
        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_number' =>
                'ENR-ROLLBACK-001',
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_archived_student_cannot_receive_new_enrollment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->archived()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-ARCHIVED-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected Archived Student Enrollment to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'An Archived Student cannot receive a new Enrollment.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_completed_course_class_cannot_receive_new_enrollment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->completed()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-COMPLETED-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected Completed Course Class Enrollment to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment is allowed only in a Planned or Active Course Class.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_cancelled_course_class_cannot_receive_new_enrollment(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->cancelled()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-CANCELLED-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected Cancelled Course Class Enrollment to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment is allowed only in a Planned or Active Course Class.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_class_duplicate_is_rejected_even_when_existing_enrollment_is_cancelled(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->cancelled()
            ->create([
                'enrollment_number' =>
                'ENR-EXISTING-001',
            ]);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-DUPLICATE-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected duplicate Student/Class Enrollment to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'The Student already has an Enrollment for this Course Class.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            1
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,

                'enrollment_number' =>
                'ENR-DUPLICATE-001',
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_enrollment_is_rejected_when_active_capacity_is_full(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 1,
            ]);

        $existingStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($existingStudent)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-CAPACITY-EXISTING',
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-CAPACITY-NEW',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected full Course Class capacity to reject Enrollment.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'The Course Class has reached its enrollment capacity.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            1
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_terminal_enrollments_do_not_consume_active_capacity(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 1,
            ]);

        $completedStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($completedStudent)
            ->forCourseClass($courseClass)
            ->completed()
            ->create([
                'enrollment_number' =>
                'ENR-CAPACITY-COMPLETED',
            ]);

        $withdrawnStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($withdrawnStudent)
            ->forCourseClass($courseClass)
            ->withdrawn()
            ->create([
                'enrollment_number' =>
                'ENR-CAPACITY-WITHDRAWN',
            ]);

        $transferredStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($transferredStudent)
            ->forCourseClass($courseClass)
            ->transferred()
            ->create([
                'enrollment_number' =>
                'ENR-CAPACITY-TRANSFERRED',
            ]);

        $cancelledStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($cancelledStudent)
            ->forCourseClass($courseClass)
            ->cancelled()
            ->create([
                'enrollment_number' =>
                'ENR-CAPACITY-CANCELLED',
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $enrollment = $this->service()
            ->enroll(
                $owner,
                $student,
                $courseClass,
                [
                    'enrollment_number' =>
                    'ENR-CAPACITY-AVAILABLE',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            1,
            Enrollment::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'class_id',
                    $courseClass->id
                )
                ->where(
                    'enrollment_status',
                    EnrollmentStatus::Active->value
                )
                ->count()
        );

        $this->assertDatabaseCount(
            'enrollments',
            5
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            1
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $enrollment
            )
        );
    }

    public function test_enrollment_is_rejected_when_required_prerequisite_course_is_not_completed(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $prerequisiteCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        DB::table(
            'course_prerequisites'
        )->insert([
            'center_id' =>
            $center->id,

            'course_id' =>
            $targetCourse->id,

            'prerequisite_course_id' =>
            $prerequisiteCourse->id,

            /*
         * requirement_type has no approved semantics yet.
         */
            'requirement_type' =>
            null,

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $targetClass,
                    [
                        'enrollment_number' =>
                        'ENR-PREREQ-MISSING',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected missing prerequisite Course to reject Enrollment.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'The Student has not completed all required prerequisite Courses.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $targetClass->id,

                'enrollment_number' =>
                'ENR-PREREQ-MISSING',
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_completed_enrollment_in_prerequisite_course_satisfies_prerequisite(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $prerequisiteCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        DB::table(
            'course_prerequisites'
        )->insert([
            'center_id' =>
            $center->id,

            'course_id' =>
            $targetCourse->id,

            'prerequisite_course_id' =>
            $prerequisiteCourse->id,

            'requirement_type' =>
            null,

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);

        $prerequisiteClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($prerequisiteCourse)
            ->completed()
            ->create([
                'capacity' => 5,
            ]);

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($prerequisiteClass)
            ->completed()
            ->create([
                'enrollment_number' =>
                'ENR-PREREQ-COMPLETED',
            ]);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $enrollment = $this->service()
            ->enroll(
                $owner,
                $student,
                $targetClass,
                [
                    'enrollment_number' =>
                    'ENR-PREREQ-TARGET',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            $targetClass->id,
            $enrollment->class_id
        );

        $this->assertDatabaseHas(
            'enrollments',
            [
                'id' =>
                $enrollment->id,

                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $targetClass->id,

                'enrollment_number' =>
                'ENR-PREREQ-TARGET',

                'enrollment_status' =>
                EnrollmentStatus::Active->value,
            ]
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'center_id' =>
                $center->id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                $targetClass->id,

                'event_type' =>
                'created',

                'new_status' =>
                EnrollmentStatus::Active->value,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $enrollment
            )
        );
    }

    public function test_branch_manager_can_enroll_student_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $enrollment = $this->service()
            ->enroll(
                $manager,
                $student,
                $courseClass,
                [
                    'enrollment_number' =>
                    'ENR-BRANCH-001',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $this->assertSame(
            $center->id,
            $enrollment->center_id
        );

        $this->assertSame(
            $student->id,
            $enrollment->student_id
        );

        $this->assertSame(
            $courseClass->id,
            $enrollment->class_id
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'center_id' =>
                $center->id,

                'enrollment_id' =>
                $enrollment->id,

                'to_class_id' =>
                $courseClass->id,

                'performed_by_user_id' =>
                $manager->id,

                'event_type' =>
                'created',

                'new_status' =>
                EnrollmentStatus::Active->value,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $enrollment
            )
        );
    }

    public function test_branch_manager_without_active_assignment_cannot_enroll_student(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        /*
     * BranchContext alone is not authority.
     *
     * EnrollmentPolicy must also find the persisted
     * active BranchManagerAssignment.
     */
        $this->establishBranchContext(
            $center,
            $branch
        );

        $rejected = false;

        try {
            $this->service()
                ->enroll(
                    $manager,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-NO-ASSIGNMENT',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );
        } catch (AuthorizationException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Expected persisted Branch Manager assignment validation to reject Enrollment.'
        );

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_branch_manager_operation_must_match_current_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $contextBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($assignedBranch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($assignedBranch)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        /*
     * Persisted authorization is valid for assignedBranch.
     */
        $this->assignManager(
            $manager,
            $assignedBranch
        );

        /*
     * But the operational request context points elsewhere.
     */
        $this->establishBranchContext(
            $center,
            $contextBranch
        );

        try {
            $this->service()
                ->enroll(
                    $manager,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-WRONG-CONTEXT',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected BranchContext mismatch to reject Enrollment.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'The Enrollment operation is outside the current Branch scope.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_cannot_enroll_resources_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseClassB = CourseClass::factory()
            ->forBranch($branchB)
            ->forCourse($courseB)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterWideContext(
            $centerA
        );

        $rejected = false;

        try {
            $this->service()
                ->enroll(
                    $ownerA,
                    $studentB,
                    $courseClassB,
                    [
                        'enrollment_number' =>
                        'ENR-CROSS-CENTER-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );
        } catch (AuthorizationException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Expected cross-Center Enrollment access to be rejected.'
        );

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_student_and_course_class_from_different_centers_cannot_be_enrolled_together(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseA = Course::factory()
            ->for($centerA)
            ->active()
            ->create();

        $courseClassA = CourseClass::factory()
            ->forBranch($branchA)
            ->forCourse($courseA)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterWideContext(
            $centerA
        );

        $rejected = false;

        try {
            $this->service()
                ->enroll(
                    $ownerA,
                    $studentB,
                    $courseClassA,
                    [
                        'enrollment_number' =>
                        'ENR-MIXED-CENTER-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );
        } catch (AuthorizationException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Expected Student and Course Class from different Centers to be rejected.'
        );

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_enrollment_requires_center_wide_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        /*
     * Center Owner Enrollment operations are center-wide.
     * A branch-scoped BranchContext must not be accepted.
     */
        $this->establishBranchContext(
            $center,
            $branch
        );

        $rejected = false;

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-OWNER-BRANCH-CONTEXT',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );
        } catch (AuthorizationException) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Expected Center Owner Enrollment to require center-wide BranchContext.'
        );

        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_enrollment_number_is_required(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected missing Enrollment number to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment number is required.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_enrollment_number_cannot_be_blank(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        '   ',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected blank Enrollment number to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment number is required.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_enrollment_number_cannot_exceed_fifty_characters(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        str_repeat(
                            'A',
                            51
                        ),

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected Enrollment number longer than 50 characters to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment number must not exceed 50 characters.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_enrollment_number_must_be_unique_within_center(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $existingStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($existingStudent)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-UNIQUE-001',
            ]);

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-UNIQUE-001',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected duplicate Enrollment number within Center to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment number already exists in this language center.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount(
            'enrollments',
            1
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $courseClass->id,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_enrollment_date_is_required(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-DATE-MISSING',
                    ]
                );

            $this->fail(
                'Expected missing Enrollment date to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment date is required.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_enrollment_date_must_use_yyyy_mm_dd_format(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-DATE-FORMAT',

                        'enrollment_date' =>
                        '26-08-2026',
                    ]
                );

            $this->fail(
                'Expected invalid Enrollment date format to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment date must use YYYY-MM-DD format.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_enrollment_date_must_be_valid_calendar_date(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        try {
            $this->service()
                ->enroll(
                    $owner,
                    $student,
                    $courseClass,
                    [
                        'enrollment_number' =>
                        'ENR-DATE-CALENDAR',

                        'enrollment_date' =>
                        '2026-02-30',
                    ]
                );

            $this->fail(
                'Expected impossible calendar date to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Enrollment date must use YYYY-MM-DD format.',
                $exception->getMessage()
            );
        }

        $this->assertNoSuccessfulEnrollmentArtifacts();
    }

    public function test_center_owner_can_withdraw_active_enrollment(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-WITHDRAW-001',
            ]);

        $withdrawalDate =
            now()->toDateString();

        $withdrawn = $this->service()
            ->withdraw(
                $owner,
                $enrollment,
                '  Student requested withdrawal  '
            );

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $withdrawn->enrollment_status
        );

        $this->assertSame(
            $withdrawalDate,
            $withdrawn
                ->withdrawal_date
                ->toDateString()
        );

        $this->assertSame(
            'Student requested withdrawal',
            $withdrawn->withdrawal_reason
        );

        $history =
            EnrollmentHistory::withoutGlobalScopes()
            ->where(
                'enrollment_id',
                $withdrawn->id
            )
            ->where(
                'event_type',
                'withdrawn'
            )
            ->firstOrFail();

        $this->assertSame(
            $courseClass->id,
            $history->from_class_id
        );

        $this->assertNull(
            $history->to_class_id
        );

        $this->assertSame(
            $owner->id,
            $history->performed_by_user_id
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $history->previous_status
        );

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $history->new_status
        );

        $this->assertSame(
            'Student requested withdrawal',
            $history->notes
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.withdrawn',
                $withdrawn
            )
        );
    }

    public function test_withdrawal_is_idempotent(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-WITHDRAW-IDEMPOTENT',
            ]);

        $service = $this->service();

        $first = $service->withdraw(
            $owner,
            $enrollment,
            'Original reason'
        );

        $firstDate =
            $first->withdrawal_date
            ->toDateString();

        $second = $service->withdraw(
            $owner,
            $first,
            'Different reason'
        );

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $second->enrollment_status
        );

        $this->assertSame(
            $firstDate,
            $second->withdrawal_date
                ->toDateString()
        );

        $this->assertSame(
            'Original reason',
            $second->withdrawal_reason
        );

        $this->assertSame(
            1,
            EnrollmentHistory::withoutGlobalScopes()
                ->where(
                    'enrollment_id',
                    $enrollment->id
                )
                ->where(
                    'event_type',
                    'withdrawn'
                )
                ->count()
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.withdrawn',
                $second
            )
        );
    }

    public function test_only_active_enrollment_can_be_withdrawn(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $cases = [
            [
                'state' => 'completed',
                'status' =>
                EnrollmentStatus::Completed,
                'number' =>
                'ENR-WITHDRAW-COMPLETED',
            ],
            [
                'state' => 'transferred',
                'status' =>
                EnrollmentStatus::Transferred,
                'number' =>
                'ENR-WITHDRAW-TRANSFERRED',
            ],
            [
                'state' => 'cancelled',
                'status' =>
                EnrollmentStatus::Cancelled,
                'number' =>
                'ENR-WITHDRAW-CANCELLED',
            ],
        ];

        foreach ($cases as $index => $case) {
            $caseStudent = $index === 0
                ? $student
                : Student::factory()
                ->forBranch($branch)
                ->active()
                ->create();

            $factory = Enrollment::factory()
                ->forStudent($caseStudent)
                ->forCourseClass($courseClass);

            $enrollment =
                $factory->{$case['state']}()
                ->create([
                    'enrollment_number' =>
                    $case['number'],
                ]);

            try {
                $this->service()
                    ->withdraw(
                        $owner,
                        $enrollment,
                        'Invalid transition'
                    );

                $this->fail(
                    'Expected non-Active Enrollment withdrawal to be rejected.'
                );
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Only an Active Enrollment can be withdrawn.',
                    $exception->getMessage()
                );
            }

            $persisted =
                Enrollment::withoutGlobalScopes()
                ->findOrFail(
                    $enrollment->id
                );

            $this->assertSame(
                $case['status'],
                $persisted->enrollment_status
            );
        }

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_branch_manager_can_withdraw_enrollment_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-BM-WITHDRAW',
            ]);

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $withdrawn = $this->service()
            ->withdraw(
                $manager,
                $enrollment,
                'Branch withdrawal'
            );

        $this->assertSame(
            EnrollmentStatus::Withdrawn,
            $withdrawn->enrollment_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.withdrawn',
                $withdrawn
            )
        );
    }

    public function test_branch_manager_withdrawal_must_match_current_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $contextBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($assignedBranch)
            ->forCourse($course)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($assignedBranch)
            ->active()
            ->create();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-BM-WRONG-CONTEXT',
            ]);

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        $this->establishBranchContext(
            $center,
            $contextBranch
        );

        try {
            $this->service()
                ->withdraw(
                    $manager,
                    $enrollment,
                    'Should fail'
                );

            $this->fail(
                'Expected BranchContext mismatch to reject withdrawal.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'The Enrollment operation is outside the current Branch scope.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'enrollments',
            [
                'id' =>
                $enrollment->id,

                'enrollment_status' =>
                EnrollmentStatus::Active->value,

                'withdrawal_date' =>
                null,

                'withdrawal_reason' =>
                null,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_cannot_withdraw_enrollment_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseClassB = CourseClass::factory()
            ->forBranch($branchB)
            ->forCourse($courseB)
            ->active()
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->active()
            ->create();

        $enrollmentB = Enrollment::factory()
            ->forStudent($studentB)
            ->forCourseClass($courseClassB)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-CROSS-CENTER-WITHDRAW',
            ]);

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterWideContext(
            $centerA
        );

        try {
            $this->service()
                ->withdraw(
                    $ownerA,
                    $enrollmentB
                );

            $this->fail(
                'Expected cross-Center withdrawal to be rejected.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'The Enrollment is outside the authorized Center scope.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'enrollments',
            [
                'id' =>
                $enrollmentB->id,

                'enrollment_status' =>
                EnrollmentStatus::Active->value,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_withdrawal_rolls_back_when_audit_recording_fails(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-WITHDRAW-ROLLBACK',
            ]);

        $this->bindFailingAudit();

        try {
            $this->service()
                ->withdraw(
                    $owner,
                    $enrollment,
                    'Rollback reason'
                );

            $this->fail(
                'Expected audit failure to abort Enrollment withdrawal.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $persisted =
            Enrollment::withoutGlobalScopes()
            ->findOrFail(
                $enrollment->id
            );

        $this->assertSame(
            EnrollmentStatus::Active,
            $persisted->enrollment_status
        );

        $this->assertNull(
            $persisted->withdrawal_date
        );

        $this->assertNull(
            $persisted->withdrawal_reason
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_withdrawal_reason_cannot_exceed_255_characters(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-WITHDRAW-LONG-REASON',
            ]);

        try {
            $this->service()
                ->withdraw(
                    $owner,
                    $enrollment,
                    str_repeat('A', 256)
                );

            $this->fail(
                'Expected oversized withdrawal reason to be rejected.'
            );
        } catch (DomainException $exception) {
            $this->assertSame(
                'Withdrawal reason must not exceed 255 characters.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas(
            'enrollments',
            [
                'id' =>
                $enrollment->id,

                'enrollment_status' =>
                EnrollmentStatus::Active->value,

                'withdrawal_date' =>
                null,

                'withdrawal_reason' =>
                null,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_can_transfer_active_enrollment_to_another_class(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $sourceCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $sourceClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($sourceCourse)
            ->active()
            ->create([
                'capacity' => 5,
            ]);

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-TRANSFER-SOURCE',
            ]);

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        $targetEnrollment =
            $this->service()
            ->transfer(
                $owner,
                $sourceEnrollment,
                $targetClass,
                [
                    'enrollment_number' =>
                    'ENR-TRANSFER-TARGET',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Transferred,
            $sourceEnrollment->enrollment_status
        );

        $this->assertSame(
            $sourceClass->id,
            $sourceEnrollment->class_id
        );

        $this->assertSame(
            EnrollmentStatus::Active,
            $targetEnrollment->enrollment_status
        );

        $this->assertSame(
            $targetClass->id,
            $targetEnrollment->class_id
        );

        $this->assertSame(
            $student->id,
            $targetEnrollment->student_id
        );

        $this->assertSame(
            'ENR-TRANSFER-TARGET',
            $targetEnrollment->enrollment_number
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $sourceEnrollment->id,

                'from_class_id' =>
                $sourceClass->id,

                'to_class_id' =>
                $targetClass->id,

                'event_type' =>
                'transfer',

                'previous_status' =>
                EnrollmentStatus::Active->value,

                'new_status' =>
                EnrollmentStatus::Transferred->value,
            ]
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $targetEnrollment->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                $targetClass->id,

                'event_type' =>
                'created',

                'previous_status' =>
                null,

                'new_status' =>
                EnrollmentStatus::Active->value,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.transferred',
                $sourceEnrollment
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $targetEnrollment
            )
        );

        $this->assertDatabaseCount(
            'enrollments',
            2
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            2
        );
    }

    public function test_transfer_to_same_target_is_idempotent(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-TRANSFER-IDEMPOTENT-SOURCE',
            ]);

        $service = $this->service();

        $first = $service->transfer(
            $owner,
            $sourceEnrollment,
            $targetClass,
            [
                'enrollment_number' =>
                'ENR-TRANSFER-IDEMPOTENT-TARGET',

                'enrollment_date' =>
                now()->toDateString(),
            ]
        );

        $second = $service->transfer(
            $owner,
            $sourceEnrollment,
            $targetClass,
            [
                /*
             * Idempotent replay must not create another
             * Enrollment even if payload differs.
             */
                'enrollment_number' =>
                'SHOULD-NOT-BE-USED',

                'enrollment_date' =>
                '2026-01-01',
            ]
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDatabaseCount(
            'enrollments',
            2
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            2
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.transferred',
                $sourceEnrollment->fresh()
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.created',
                $first
            )
        );
    }

    public function test_enrollment_cannot_be_transferred_to_same_class(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'An Enrollment cannot be transferred to the same Course Class.'
        );

        $this->service()
            ->transfer(
                $owner,
                $enrollment,
                $courseClass,
                [
                    'enrollment_number' =>
                    'ENR-SAME-CLASS',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_only_active_enrollment_can_be_transferred(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        $cases = [
            'completed',
            'withdrawn',
            'cancelled',
        ];

        foreach ($cases as $index => $state) {
            $caseStudent = $index === 0
                ? $student
                : Student::factory()
                ->forBranch($branch)
                ->active()
                ->create();

            $enrollment =
                Enrollment::factory()
                ->forStudent($caseStudent)
                ->forCourseClass($sourceClass)
                ->{$state}()
                ->create();

            try {
                $this->service()
                    ->transfer(
                        $owner,
                        $enrollment,
                        $targetClass,
                        [
                            'enrollment_number' =>
                            "ENR-INVALID-TRANSFER-{$index}",

                            'enrollment_date' =>
                            now()->toDateString(),
                        ]
                    );

                $this->fail(
                    'Expected non-Active Enrollment transfer to be rejected.'
                );
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Only an Active Enrollment can be transferred.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_transfer_target_must_be_planned_or_active(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        foreach (
            [
                'completed',
                'cancelled',
            ] as $index => $state
        ) {
            $course = Course::factory()
                ->for($center)
                ->active()
                ->create();

            $target =
                CourseClass::factory()
                ->forBranch($branch)
                ->forCourse($course)
                ->{$state}()
                ->create();

            try {
                $this->service()
                    ->transfer(
                        $owner,
                        $sourceEnrollment,
                        $target,
                        [
                            'enrollment_number' =>
                            "ENR-TARGET-STATE-{$index}",

                            'enrollment_date' =>
                            now()->toDateString(),
                        ]
                    );

                $this->fail(
                    'Expected invalid target Class lifecycle to reject transfer.'
                );
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Transfer is allowed only to a Planned or Active Course Class.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_transfer_rejects_full_target_class(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->active()
            ->create([
                'capacity' => 1,
            ]);

        $existingStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        Enrollment::factory()
            ->forStudent($existingStudent)
            ->forCourseClass($targetClass)
            ->active()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Course Class has reached its enrollment capacity.'
        );

        $this->service()
            ->transfer(
                $owner,
                $sourceEnrollment,
                $targetClass,
                [
                    'enrollment_number' =>
                    'ENR-FULL-TARGET',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_transfer_rechecks_target_course_prerequisites(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $prerequisiteCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        DB::table(
            'course_prerequisites'
        )->insert([
            'center_id' =>
            $center->id,

            'course_id' =>
            $targetCourse->id,

            'prerequisite_course_id' =>
            $prerequisiteCourse->id,

            'requirement_type' =>
            null,

            'created_at' =>
            now(),

            'updated_at' =>
            now(),
        ]);

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Student has not completed all required prerequisite Courses.'
        );

        $this->service()
            ->transfer(
                $owner,
                $sourceEnrollment,
                $targetClass,
                [
                    'enrollment_number' =>
                    'ENR-TRANSFER-PREREQ',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_existing_target_enrollment_blocks_transfer_even_when_terminal(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($targetClass)
            ->cancelled()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $this->expectException(
            DomainException::class
        );

        $this->expectExceptionMessage(
            'The Student already has an Enrollment for this Course Class.'
        );

        $this->service()
            ->transfer(
                $owner,
                $sourceEnrollment,
                $targetClass,
                [
                    'enrollment_number' =>
                    'ENR-DUPLICATE-TARGET',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_branch_manager_can_transfer_only_inside_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $sourceCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $sourceClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($sourceCourse)
            ->active()
            ->create();

        $sameBranchTarget = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        $crossBranchTarget = CourseClass::factory()
            ->forBranch($otherBranch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $targetEnrollment =
            $this->service()
            ->transfer(
                $manager,
                $sourceEnrollment,
                $sameBranchTarget,
                [
                    'enrollment_number' =>
                    'ENR-BM-TRANSFER',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );

        $this->assertSame(
            EnrollmentStatus::Active,
            $targetEnrollment->enrollment_status
        );

        /*
     * Use another source Enrollment to test cross-Branch.
     */
        $otherStudent = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $otherSource =
            Enrollment::factory()
            ->forStudent($otherStudent)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->transfer(
                $manager,
                $otherSource,
                $crossBranchTarget,
                [
                    'enrollment_number' =>
                    'ENR-BM-CROSS-BRANCH',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_transfer_rejects_target_class_from_another_center(): void
    {
        [
            $centerA,
            $branchA,
            $sourceClass,
            $student,
            $ownerA,
        ] = $this->validCenterOwnerEnrollmentContext();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->active()
            ->create();

        $targetClassB = CourseClass::factory()
            ->forBranch($branchB)
            ->forCourse($courseB)
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create();

        $this->expectException(
            AuthorizationException::class
        );

        $this->expectExceptionMessage(
            'The Course Class is outside the authorized Center scope.'
        );

        $this->service()
            ->transfer(
                $ownerA,
                $sourceEnrollment,
                $targetClassB,
                [
                    'enrollment_number' =>
                    'ENR-CROSS-CENTER-TARGET',

                    'enrollment_date' =>
                    now()->toDateString(),
                ]
            );
    }

    public function test_transfer_rolls_back_when_audit_recording_fails(): void
    {
        [
            $center,
            $branch,
            $sourceClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targetCourse = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $targetClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($targetCourse)
            ->planned()
            ->create();

        $sourceEnrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($sourceClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-TRANSFER-ROLLBACK-SOURCE',
            ]);

        $this->bindFailingAudit();

        try {
            $this->service()
                ->transfer(
                    $owner,
                    $sourceEnrollment,
                    $targetClass,
                    [
                        'enrollment_number' =>
                        'ENR-TRANSFER-ROLLBACK-TARGET',

                        'enrollment_date' =>
                        now()->toDateString(),
                    ]
                );

            $this->fail(
                'Expected audit failure to abort Enrollment transfer.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $sourceEnrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $sourceEnrollment->enrollment_status
        );

        $this->assertDatabaseMissing(
            'enrollments',
            [
                'center_id' =>
                $center->id,

                'student_id' =>
                $student->id,

                'class_id' =>
                $targetClass->id,
            ]
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_can_complete_active_enrollment(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-STATUS-COMPLETE',
            ]);

        $completed =
            $this->service()
            ->updateStatus(
                $owner,
                $enrollment,
                EnrollmentStatus::Completed
            );

        $this->assertSame(
            EnrollmentStatus::Completed,
            $completed->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'center_id' =>
                $center->id,

                'enrollment_id' =>
                $completed->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                null,

                'performed_by_user_id' =>
                $owner->id,

                'event_type' =>
                'completed',

                'previous_status' =>
                EnrollmentStatus::Active->value,

                'new_status' =>
                EnrollmentStatus::Completed->value,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.completed',
                $completed
            )
        );
    }

    public function test_center_owner_can_cancel_active_enrollment(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-STATUS-CANCEL',
            ]);

        $cancelled =
            $this->service()
            ->updateStatus(
                $owner,
                $enrollment,
                EnrollmentStatus::Cancelled
            );

        $this->assertSame(
            EnrollmentStatus::Cancelled,
            $cancelled->enrollment_status
        );

        $this->assertDatabaseHas(
            'enrollment_histories',
            [
                'enrollment_id' =>
                $cancelled->id,

                'event_type' =>
                'cancelled',

                'previous_status' =>
                EnrollmentStatus::Active->value,

                'new_status' =>
                EnrollmentStatus::Cancelled->value,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.cancelled',
                $cancelled
            )
        );
    }

    public function test_enrollment_status_update_is_idempotent_for_same_terminal_status(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-STATUS-IDEMPOTENT',
            ]);

        $service = $this->service();

        $first = $service->updateStatus(
            $owner,
            $enrollment,
            EnrollmentStatus::Completed
        );

        $second = $service->updateStatus(
            $owner,
            $first,
            EnrollmentStatus::Completed
        );

        $this->assertSame(
            EnrollmentStatus::Completed,
            $second->enrollment_status
        );

        $this->assertSame(
            1,
            EnrollmentHistory::withoutGlobalScopes()
                ->where(
                    'enrollment_id',
                    $enrollment->id
                )
                ->where(
                    'event_type',
                    'completed'
                )
                ->count()
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.completed',
                $second
            )
        );
    }

    public function test_terminal_enrollment_status_cannot_change_to_another_terminal_status(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $cases = [
            [
                'state' =>
                'completed',

                'target' =>
                EnrollmentStatus::Cancelled,
            ],
            [
                'state' =>
                'cancelled',

                'target' =>
                EnrollmentStatus::Completed,
            ],
            [
                'state' =>
                'withdrawn',

                'target' =>
                EnrollmentStatus::Completed,
            ],
            [
                'state' =>
                'transferred',

                'target' =>
                EnrollmentStatus::Completed,
            ],
        ];

        foreach ($cases as $index => $case) {
            $caseStudent =
                $index === 0
                ? $student
                : Student::factory()
                ->forBranch($branch)
                ->active()
                ->create();

            $enrollment =
                Enrollment::factory()
                ->forStudent($caseStudent)
                ->forCourseClass($courseClass)
                ->{$case['state']}()
                ->create([
                    'enrollment_number' =>
                    "ENR-STATUS-TERMINAL-{$index}",
                ]);

            try {
                $this->service()
                    ->updateStatus(
                        $owner,
                        $enrollment,
                        $case['target']
                    );

                $this->fail(
                    'Expected terminal Enrollment status transition to be rejected.'
                );
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Only an Active Enrollment may change to Completed or Cancelled.',
                    $exception->getMessage()
                );
            }
        }

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_generic_status_update_rejects_statuses_managed_by_other_lifecycle_operations(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $targets = [
            EnrollmentStatus::Active,
            EnrollmentStatus::Withdrawn,
            EnrollmentStatus::Transferred,
        ];

        foreach ($targets as $index => $target) {
            $caseStudent =
                $index === 0
                ? $student
                : Student::factory()
                ->forBranch($branch)
                ->active()
                ->create();

            $enrollment =
                Enrollment::factory()
                ->forStudent($caseStudent)
                ->forCourseClass($courseClass)
                ->active()
                ->create([
                    'enrollment_number' =>
                    "ENR-INVALID-TARGET-{$index}",
                ]);

            try {
                $this->service()
                    ->updateStatus(
                        $owner,
                        $enrollment,
                        $target
                    );

                $this->fail(
                    'Expected unsupported generic Enrollment status target to be rejected.'
                );
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Enrollment status may be updated only to Completed or Cancelled.',
                    $exception->getMessage()
                );
            }
        }

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_branch_manager_can_update_enrollment_status_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchContext(
            $center,
            $branch
        );

        $updated =
            $this->service()
            ->updateStatus(
                $manager,
                $enrollment,
                EnrollmentStatus::Completed
            );

        $this->assertSame(
            EnrollmentStatus::Completed,
            $updated->enrollment_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'enrollment.completed',
                $updated
            )
        );
    }

    public function test_enrollment_status_update_must_match_current_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $assignedBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $contextBranch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($assignedBranch)
            ->forCourse($course)
            ->active()
            ->create();

        $student = Student::factory()
            ->forBranch($assignedBranch)
            ->active()
            ->create();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $assignedBranch
        );

        $this->establishBranchContext(
            $center,
            $contextBranch
        );

        try {
            $this->service()
                ->updateStatus(
                    $manager,
                    $enrollment,
                    EnrollmentStatus::Completed
                );

            $this->fail(
                'Expected BranchContext mismatch to reject Enrollment status update.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'The Enrollment operation is outside the current Branch scope.',
                $exception->getMessage()
            );
        }

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_center_owner_cannot_update_enrollment_status_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->active()
            ->create();

        $courseClassB = CourseClass::factory()
            ->forBranch($branchB)
            ->forCourse($courseB)
            ->active()
            ->create();

        $studentB = Student::factory()
            ->forBranch($branchB)
            ->active()
            ->create();

        $enrollmentB = Enrollment::factory()
            ->forStudent($studentB)
            ->forCourseClass($courseClassB)
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterWideContext(
            $centerA
        );

        try {
            $this->service()
                ->updateStatus(
                    $ownerA,
                    $enrollmentB,
                    EnrollmentStatus::Completed
                );

            $this->fail(
                'Expected cross-Center Enrollment status update to be rejected.'
            );
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'The Enrollment is outside the authorized Center scope.',
                $exception->getMessage()
            );
        }

        $enrollmentB->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollmentB->enrollment_status
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    public function test_enrollment_status_update_rolls_back_when_audit_recording_fails(): void
    {
        [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ] = $this->validCenterOwnerEnrollmentContext();

        $enrollment = Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass($courseClass)
            ->active()
            ->create([
                'enrollment_number' =>
                'ENR-STATUS-ROLLBACK',
            ]);

        $this->bindFailingAudit();

        try {
            $this->service()
                ->updateStatus(
                    $owner,
                    $enrollment,
                    EnrollmentStatus::Completed
                );

            $this->fail(
                'Expected audit failure to abort Enrollment status update.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $enrollment->refresh();

        $this->assertSame(
            EnrollmentStatus::Active,
            $enrollment->enrollment_status
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    /**
     * @return array{
     *     0: Center,
     *     1: Branch,
     *     2: CourseClass,
     *     3: Student,
     *     4: User
     * }
     */
    private function validCenterOwnerEnrollmentContext(): array
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $courseClass = CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->planned()
            ->create([
                'capacity' => 5,
            ]);

        $student = Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $this->establishCenterWideContext(
            $center
        );

        return [
            $center,
            $branch,
            $courseClass,
            $student,
            $owner,
        ];
    }

    private function assertNoSuccessfulEnrollmentArtifacts(): void
    {
        $this->assertDatabaseCount(
            'enrollments',
            0
        );

        $this->assertDatabaseCount(
            'enrollment_histories',
            0
        );

        $this->assertDatabaseCount(
            'audit_records',
            0
        );
    }

    private function bindFailingAudit(): void
    {
        $failingAudit = \Mockery::mock(
            AuditRecorder::class
        );

        $failingAudit
            ->shouldReceive('record')
            ->once()
            ->andThrow(
                new LogicException(
                    'Simulated audit failure.'
                )
            );

        $this->app->instance(
            AuditRecorder::class,
            $failingAudit
        );
    }

    private function service(): EnrollmentManagementService
    {
        return app(
            EnrollmentManagementService::class
        );
    }

    private function establishCenterWideContext(
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

    private function assignManager(
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

                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
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

                'status' =>
                AccountStatus::Active,
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

    private function auditCount(
        string $actionType,
        Enrollment $enrollment
    ): int {
        return AuditRecord::query()
            ->where(
                'action_type',
                $actionType
            )
            ->where(
                'subject_type',
                $enrollment->getTable()
            )
            ->where(
                'subject_id',
                $enrollment->id
            )
            ->count();
    }
}
