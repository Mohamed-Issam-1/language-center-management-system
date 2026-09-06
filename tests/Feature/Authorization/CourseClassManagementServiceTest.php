<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseClasses\CourseClassManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseClassManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_course_class_with_valid_active_resources(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $this->establishCenterOwnerContext(
            $center
        );

        $courseClass = $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                [
                    'center_id' => 999999,
                    'branch_id' => 999999,
                    'course_id' => 999999,

                    'assigned_classroom_id' =>
                    999999,

                    'assigned_teacher_id' =>
                    999999,

                    'class_status' =>
                    CourseClassStatus::Completed,

                    'class_code' =>
                    'ENG-A1-01',

                    'name' =>
                    'English A1 Morning',

                    'start_date' =>
                    '2026-09-01',

                    'end_date' =>
                    '2026-10-31',

                    'capacity' => 20,

                    'delivery_mode' =>
                    'onsite',
                ]
            );

        $this->assertSame(
            $center->id,
            $courseClass->center_id
        );

        $this->assertSame(
            $branch->id,
            $courseClass->branch_id
        );

        $this->assertSame(
            $course->id,
            $courseClass->course_id
        );

        $this->assertSame(
            $classroom->id,
            $courseClass
                ->assigned_classroom_id
        );

        $this->assertSame(
            $teacher->id,
            $courseClass
                ->assigned_teacher_id
        );

        $this->assertSame(
            CourseClassStatus::Planned,
            $courseClass->class_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.created',
                $courseClass
            )
        );
    }

    public function test_branch_manager_can_create_course_class_in_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        $courseClass = $this->service()
            ->create(
                $manager,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );

        $this->assertSame(
            $branch->id,
            $courseClass->branch_id
        );
    }

    public function test_branch_manager_cannot_create_course_class_outside_context_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [
            $branchB,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branchA
        );

        $this->establishBranchManagerContext(
            $center,
            $branchA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->create(
                $manager,
                $branchB,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_deactivated_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $branch->update([
            'status' =>
            BranchStatus::Deactivated,
        ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_archived_course(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $course->update([
            'status' =>
            AcademicRecordStatus::Archived,

            'archived_at' => now(),
        ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_deactivated_classroom(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $classroom->update([
            'status' =>
            ClassroomStatus::Deactivated,
        ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_unavailable_classroom(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $classroom->update([
            'availability_status' =>
            ClassroomAvailabilityStatus::Unavailable,
        ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_deactivated_teacher(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $teacher->update([
            'status' =>
            StaffStatus::Deactivated,

            'deactivated_at' => now(),
        ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $this->validAttributes()
            );
    }

    public function test_course_class_creation_rejects_capacity_above_classroom_capacity(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $attributes =
            $this->validAttributes();

        $attributes['capacity'] =
            $classroom->capacity + 1;

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $attributes
            );
    }

    public function test_course_class_creation_rejects_invalid_date_range(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $attributes =
            $this->validAttributes();

        $attributes['start_date'] =
            '2026-10-01';

        $attributes['end_date'] =
            '2026-09-01';

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $attributes
            );
    }

    public function test_course_class_creation_rejects_invalid_calendar_date(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $attributes =
            $this->validAttributes();

        $attributes['start_date'] =
            '2026-02-31';

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                $attributes
            );
    }

    public function test_course_class_update_cannot_move_center_branch_course_or_lifecycle(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->create();

        $otherCourse = Course::factory()
            ->for($center)
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'center_id' => 999999,
                    'branch_id' =>
                    $otherBranch->id,
                    'course_id' =>
                    $otherCourse->id,

                    'class_status' =>
                    CourseClassStatus::Completed,

                    'name' =>
                    'Updated Class',
                ]
            );

        $this->assertSame(
            $center->id,
            $updated->center_id
        );

        $this->assertSame(
            $branch->id,
            $updated->branch_id
        );

        $this->assertSame(
            $course->id,
            $updated->course_id
        );

        $this->assertSame(
            CourseClassStatus::Planned,
            $updated->class_status
        );

        $this->assertSame(
            'Updated Class',
            $updated->name
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.updated',
                $updated
            )
        );
    }

    public function test_course_class_can_safely_reassign_classroom_and_teacher(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create([
                'capacity' => 15,
            ]);

        $newClassroom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create([
                'capacity' => 25,
            ]);

        $newTeacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $updated = $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'assigned_classroom_id' =>
                    $newClassroom->id,

                    'assigned_teacher_id' =>
                    $newTeacher->id,
                ]
            );

        $this->assertSame(
            $newClassroom->id,
            $updated
                ->assigned_classroom_id
        );

        $this->assertSame(
            $newTeacher->id,
            $updated
                ->assigned_teacher_id
        );
    }

    public function test_course_class_cannot_reassign_classroom_from_another_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create();

        $otherBranch = Branch::factory()
            ->for($center)
            ->create();

        $otherClassroom =
            Classroom::factory()
            ->forBranch($otherBranch)
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'assigned_classroom_id' =>
                    $otherClassroom->id,
                ]
            );
    }

    public function test_course_class_cannot_reassign_teacher_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $centerA
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create();

        $otherTeacher =
            Teacher::factory()
            ->create([
                'center_id' =>
                $centerB->id,
            ]);

        $this->establishCenterOwnerContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'assigned_teacher_id' =>
                    $otherTeacher->id,
                ]
            );
    }

    public function test_course_class_update_revalidates_capacity_against_classroom(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create([
                'capacity' => 15,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'capacity' =>
                    $classroom->capacity + 1,
                ]
            );
    }

    public function test_course_class_update_revalidates_combined_date_range(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create([
                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-10-01',
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'start_date' =>
                    '2026-11-01',
                ]
            );
    }

    public function test_no_change_course_class_update_creates_no_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->create([
                'name' =>
                'Existing Class',
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->service()
            ->update(
                $owner,
                $courseClass,
                [
                    'name' =>
                    'Existing Class',
                ]
            );

        $this->assertSame(
            0,
            $this->auditCount(
                'course_class.updated',
                $courseClass
            )
        );
    }

    public function test_planned_course_class_can_be_activated_with_valid_operational_resources(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create([
                'capacity' => 20,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $activated = $this->service()
            ->activate(
                $owner,
                $courseClass
            );

        $this->assertSame(
            CourseClassStatus::Active,
            $activated->class_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.activated',
                $activated
            )
        );
    }

    public function test_course_class_activation_rechecks_persisted_branch_status(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        Branch::query()
            ->whereKey($branch->id)
            ->update([
                'status' =>
                BranchStatus::Deactivated,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activate(
                $owner,
                $courseClass
            );
    }

    public function test_course_class_activation_rechecks_persisted_course_status(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        Course::query()
            ->whereKey($course->id)
            ->update([
                'status' =>
                AcademicRecordStatus::Archived,

                'archived_at' => now(),
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activate(
                $owner,
                $courseClass
            );
    }

    public function test_course_class_activation_rechecks_classroom_status_and_availability(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        Classroom::query()
            ->whereKey($classroom->id)
            ->update([
                'availability_status' =>
                ClassroomAvailabilityStatus::Unavailable,
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activate(
                $owner,
                $courseClass
            );
    }

    public function test_course_class_activation_rechecks_teacher_status(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        Teacher::query()
            ->whereKey($teacher->id)
            ->update([
                'status' =>
                StaffStatus::Deactivated,

                'deactivated_at' => now(),
            ]);

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activate(
                $owner,
                $courseClass
            );
    }

    public function test_active_course_class_can_be_completed(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $completed = $this->service()
            ->complete(
                $owner,
                $courseClass
            );

        $this->assertSame(
            CourseClassStatus::Completed,
            $completed->class_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.completed',
                $completed
            )
        );
    }

    public function test_planned_course_class_cannot_be_completed_directly(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->planned()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->complete(
                $owner,
                $courseClass
            );
    }

    public function test_planned_course_class_can_be_cancelled(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->planned()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $cancelled = $this->service()
            ->cancel(
                $owner,
                $courseClass
            );

        $this->assertSame(
            CourseClassStatus::Cancelled,
            $cancelled->class_status
        );
    }

    public function test_active_course_class_can_be_cancelled(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $cancelled = $this->service()
            ->cancel(
                $owner,
                $courseClass
            );

        $this->assertSame(
            CourseClassStatus::Cancelled,
            $cancelled->class_status
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.cancelled',
                $cancelled
            )
        );
    }

    public function test_completed_course_class_cannot_be_cancelled(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->completed()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->cancel(
                $owner,
                $courseClass
            );
    }

    public function test_cancelled_course_class_cannot_be_reactivated(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $courseClass =
            CourseClass::factory()
            ->for($center)
            ->cancelled()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->activate(
                $owner,
                $courseClass
            );
    }

    public function test_course_class_lifecycle_operations_are_idempotent_for_same_target_state(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $activeClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        $completedClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->active()
            ->create();

        $cancelledClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        $this->establishCenterOwnerContext(
            $center
        );

        $this->service()
            ->activate(
                $owner,
                $activeClass
            );

        $this->service()
            ->activate(
                $owner,
                $activeClass
            );

        $this->service()
            ->complete(
                $owner,
                $completedClass
            );

        $this->service()
            ->complete(
                $owner,
                $completedClass
            );

        $this->service()
            ->cancel(
                $owner,
                $cancelledClass
            );

        $this->service()
            ->cancel(
                $owner,
                $cancelledClass
            );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.activated',
                $activeClass
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.completed',
                $completedClass
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course_class.cancelled',
                $cancelledClass
            )
        );
    }

    public function test_branch_manager_can_transition_course_class_only_inside_assigned_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [
            $branch,
            $course,
            $classroom,
            $teacher,
        ] = $this->validResources(
            $center
        );

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->assignManager(
            $manager,
            $branch
        );

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forCourse($course)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->planned()
            ->create();

        $this->establishBranchManagerContext(
            $center,
            $branch
        );

        $activated = $this->service()
            ->activate(
                $manager,
                $courseClass
            );

        $this->assertSame(
            CourseClassStatus::Active,
            $activated->class_status
        );
    }

    private function service(): CourseClassManagementService
    {
        return app(
            CourseClassManagementService::class
        );
    }

    /**
     * @return array{
     *     0: Branch,
     *     1: Course,
     *     2: Classroom,
     *     3: Teacher
     * }
     */
    private function validResources(
        Center $center
    ): array {
        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course = Course::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create([
                'capacity' => 30,
            ]);

        $teacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        return [
            $branch,
            $course,
            $classroom,
            $teacher,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validAttributes(): array
    {
        return [
            'class_code' =>
            'ENG-A1-01',

            'name' =>
            'English A1 Morning',

            'start_date' =>
            '2026-09-01',

            'end_date' =>
            '2026-10-31',

            'capacity' => 20,

            'delivery_mode' =>
            'onsite',
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

                'started_at' => now(),
                'ended_at' => null,
                'active_marker' => 1,
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
                    'center_id' => null,
                    'person_id' => null,

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
        CourseClass $courseClass
    ): int {
        return AuditRecord::query()
            ->where(
                'action_type',
                $actionType
            )
            ->where(
                'subject_type',
                $courseClass->getTable()
            )
            ->where(
                'subject_id',
                $courseClass->id
            )
            ->count();
    }
}
