<?php

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Attendance\AttendanceManagementService;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AttendanceManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_assigned_teacher_can_record_attendance_without_branch_context(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    10,

                    'excuse' =>
                    '  Transportation delay.  ',

                    'notes' =>
                    '  Arrived after start.  ',
                ]
            );

        $this->assertSame(
            $center->id,
            $attendance->center_id
        );

        $this->assertSame(
            $session->id,
            $attendance->session_id
        );

        $this->assertSame(
            $enrollment->id,
            $attendance->enrollment_id
        );

        $this->assertSame(
            $status->id,
            $attendance
                ->attendance_status_id
        );

        $this->assertSame(
            $teacherUser->id,
            $attendance
                ->recorded_by_user_id
        );

        $this->assertSame(
            10,
            $attendance->late_minutes
        );

        $this->assertSame(
            'Transportation delay.',
            $attendance->excuse
        );

        $this->assertSame(
            'Arrived after start.',
            $attendance->notes
        );

        $this->assertNotNull(
            $attendance->recorded_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance.recorded',
                $attendance
            )
        );
    }

    public function test_attendance_can_be_recorded_for_completed_session(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $session->forceFill([
            'session_status' =>
            ClassSessionStatus::Completed,
        ])->save();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->assertTrue(
            $attendance->exists
        );
    }

    public function test_cancelled_session_rejects_new_attendance(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $session->forceFill([
            'session_status' =>
            ClassSessionStatus::Cancelled,

            'cancellation_reason' =>
            'Cancelled.',
        ])->save();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_inactive_status_cannot_be_used_for_new_attendance(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
        ] = $this->teacherAttendanceContext();

        $inactive =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->inactive()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $inactive->id,
                ]
            );
    }

    public function test_attendance_cannot_precede_enrollment_date(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $enrollment->forceFill([
            'enrollment_date' =>
            $session
                ->session_date
                ->copy()
                ->addDay()
                ->toDateString(),
        ])->save();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_withdrawn_enrollment_accepts_only_historical_attendance_on_or_before_withdrawal_date(): void
    {
        [
            $center,,
            $teacherUser,
            $teacher,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Withdrawn,

            'withdrawal_date' =>
            $session
                ->session_date
                ->toDateString(),
        ])->save();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->assertTrue(
            $attendance->exists
        );

        [,,,,
            $laterSession,
            $laterEnrollment,
            $laterStatus,
        ] = $this->teacherAttendanceContext(
            $center,
            null,
            $teacherUser,
            $teacher
        );

        $laterEnrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Withdrawn,

            'withdrawal_date' =>
            $laterSession
                ->session_date
                ->copy()
                ->subDay()
                ->toDateString(),
        ])->save();

        try {
            $this->service()
                ->record(
                    $teacherUser,
                    $laterSession,
                    $laterEnrollment,
                    [
                        'attendance_status_id' =>
                        $laterStatus->id,
                    ]
                );

            $this->fail(
                'Expected post-withdrawal Attendance to be rejected.'
            );
        } catch (DomainException) {
            $this->assertDatabaseMissing(
                'attendances',
                [
                    'session_id' =>
                    $laterSession->id,

                    'enrollment_id' =>
                    $laterEnrollment->id,
                ]
            );
        }
    }

    public function test_transferred_enrollment_accepts_only_attendance_before_transfer_history_date(): void
    {
        [
            $center,
            $branch,
            $teacherUser,
            $teacher,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $targetClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forTeacher($teacher)
            ->active()
            ->create();

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Transferred,
        ])->save();

        $this->createTransferHistory(
            $enrollment,
            $targetClass,
            $teacherUser,
            $session
                ->session_date
                ->copy()
                ->endOfDay()
        );

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->assertTrue(
            $attendance->exists
        );

        [,
            $branchB,,,
            $laterSession,
            $laterEnrollment,
            $laterStatus,
        ] = $this->teacherAttendanceContext(
            $center,
            null,
            $teacherUser,
            $teacher
        );

        $targetClassB =
            CourseClass::factory()
            ->forBranch($branchB)
            ->forTeacher($teacher)
            ->active()
            ->create();

        $laterEnrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Transferred,
        ])->save();

        $this->createTransferHistory(
            $laterEnrollment,
            $targetClassB,
            $teacherUser,
            $laterSession
                ->session_date
                ->copy()
                ->subDay()
                ->endOfDay()
        );

        try {
            $this->service()
                ->record(
                    $teacherUser,
                    $laterSession,
                    $laterEnrollment,
                    [
                        'attendance_status_id' =>
                        $laterStatus->id,
                    ]
                );

            $this->fail(
                'Expected post-transfer Attendance to be rejected.'
            );
        } catch (DomainException) {
            $this->assertDatabaseMissing(
                'attendances',
                [
                    'session_id' =>
                    $laterSession->id,

                    'enrollment_id' =>
                    $laterEnrollment->id,
                ]
            );
        }
    }

    public function test_cancelled_enrollment_cannot_receive_new_attendance(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Cancelled,
        ])->save();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_duplicate_attendance_is_rejected(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_teacher_cannot_record_attendance_for_another_teacher_session(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        [,,
            $teacherUser,
        ] = $this->teacherAttendanceContext(
            $center
        );

        [,,,,
            $otherSession,
            $otherEnrollment,
            $status,
        ] = $this->teacherAttendanceContext(
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $otherSession,
                $otherEnrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_branch_manager_requires_exact_branch_context(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branchA
        );

        [,,,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext(
            $center,
            $branchA
        );

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->record(
                    $manager,
                    $session,
                    $enrollment,
                    [
                        'attendance_status_id' =>
                        $status->id,
                    ]
                );

            $this->fail(
                'Expected missing Branch context to fail.'
            );
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(
                'attendances',
                0
            );
        }

        app(BranchContext::class)
            ->establishBranchScope(
                $branchB
            );

        try {
            $this->service()
                ->record(
                    $manager,
                    $session,
                    $enrollment,
                    [
                        'attendance_status_id' =>
                        $status->id,
                    ]
                );

            $this->fail(
                'Expected wrong Branch context to fail.'
            );
        } catch (AuthorizationException) {
            $this->assertDatabaseCount(
                'attendances',
                0
            );
        }

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $attendance =
            $this->service()
            ->record(
                $manager,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->assertSame(
            $manager->id,
            $attendance
                ->recorded_by_user_id
        );
    }

    public function test_branch_manager_attendance_scope_follows_class_branch_not_student_home_branch(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $studentHomeBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classBranch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        [,,,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext(
            $center,
            $classBranch
        );

        /*
     * This represents a valid Center Owner cross-Branch
     * Enrollment/transfer scenario:
     *
     * Student administrative Branch = A
     * actual Course Class Branch    = B
     */
        $student =
            Student::query()
            ->withoutGlobalScopes()
            ->findOrFail(
                $enrollment->student_id
            );

        $student->forceFill([
            'branch_id' =>
            $studentHomeBranch->id,
        ])->save();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $classBranch
        );

        $this->establishCenterContext(
            $center
        );

        app(BranchContext::class)
            ->establishBranchScope(
                $classBranch
            );

        $attendance =
            $this->service()
            ->record(
                $manager,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $this->assertSame(
            $manager->id,
            $attendance->recorded_by_user_id
        );

        $this->assertSame(
            $session->id,
            $attendance->session_id
        );

        $this->assertSame(
            $enrollment->id,
            $attendance->enrollment_id
        );
    }

    public function test_account_and_tenant_center_must_match(): void
    {
        [
            $centerA,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $centerB = Center::factory()
            ->active()
            ->create();

        $this->establishCenterContext(
            $centerB
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );
    }

    public function test_attendance_update_preserves_original_recorder_and_recorded_time(): void
    {
        [
            $center,
            $branch,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,
                ]
            );

        $originalRecorderId =
            $attendance
            ->recorded_by_user_id;

        $originalRecordedAt =
            $attendance
            ->recorded_at
            ->toDateTimeString();

        $manager =
            $this->createUserForRole(
                SystemRole::BranchManager,
                $center
            );

        $this->assignManager(
            $manager,
            $branch
        );

        app(BranchContext::class)
            ->establishBranchScope(
                $branch
            );

        $newStatus =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create();

        $updated =
            $this->service()
            ->update(
                $manager,
                $attendance,
                [
                    'attendance_status_id' =>
                    $newStatus->id,

                    'late_minutes' =>
                    15,

                    'excuse' =>
                    'Approved excuse.',

                    'notes' =>
                    'Corrected by Branch Manager.',
                ]
            );

        $this->assertSame(
            $newStatus->id,
            $updated
                ->attendance_status_id
        );

        $this->assertSame(
            15,
            $updated->late_minutes
        );

        $this->assertSame(
            $originalRecorderId,
            $updated
                ->recorded_by_user_id
        );

        $this->assertSame(
            $originalRecordedAt,
            $updated
                ->recorded_at
                ->toDateTimeString()
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'attendance.updated',
                $updated
            )
        );
    }

    public function test_update_cannot_change_to_inactive_status(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $inactive =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->inactive()
            ->create();

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $teacherUser,
                $attendance,
                [
                    'attendance_status_id' =>
                    $inactive->id,
                ]
            );
    }

    public function test_historical_attendance_remains_correctable_after_withdrawal(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $enrollment->forceFill([
            'enrollment_status' =>
            EnrollmentStatus::Withdrawn,

            'withdrawal_date' =>
            now()->toDateString(),
        ])->save();

        $updated =
            $this->service()
            ->update(
                $teacherUser,
                $attendance,
                [
                    'notes' =>
                    'Historical correction.',
                ]
            );

        $this->assertSame(
            'Historical correction.',
            $updated->notes
        );
    }

    public function test_cancelled_session_rejects_attendance_update(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,
                ]
            );

        $session->forceFill([
            'session_status' =>
            ClassSessionStatus::Cancelled,

            'cancellation_reason' =>
            'Cancelled after initial record.',
        ])->save();

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->update(
                $teacherUser,
                $attendance,
                [
                    'notes' =>
                    'Should fail.',
                ]
            );
    }

    public function test_no_change_update_creates_no_audit_record(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            );

        $this->service()
            ->update(
                $teacherUser,
                $attendance,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'excuse' =>
                    null,

                    'notes' =>
                    null,
                ]
            );

        $this->assertSame(
            0,
            $this->auditCount(
                'attendance.updated',
                $attendance
            )
        );
    }

    public function test_unsupported_or_invalid_attendance_input_is_rejected(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $invalidCases = [
            [
                'center_id' =>
                $center->id,

                'attendance_status_id' =>
                $status->id,
            ],

            [
                'attendance_status_id' =>
                $status->id,

                'recorded_by_user_id' =>
                $teacherUser->id,
            ],

            [
                'attendance_status_id' =>
                0,
            ],

            [
                'attendance_status_id' =>
                $status->id,

                'late_minutes' =>
                -1,
            ],

            [
                'attendance_status_id' =>
                $status->id,

                'late_minutes' =>
                1.5,
            ],

            [
                'attendance_status_id' =>
                $status->id,

                'excuse' =>
                str_repeat(
                    'A',
                    256
                ),
            ],
        ];

        foreach ($invalidCases as $attributes) {
            try {
                $this->service()
                    ->record(
                        $teacherUser,
                        $session,
                        $enrollment,
                        $attributes
                    );

                $this->fail(
                    'Expected invalid Attendance input to fail.'
                );
            } catch (DomainException) {
                $this->assertDatabaseMissing(
                    'attendances',
                    [
                        'session_id' =>
                        $session->id,

                        'enrollment_id' =>
                        $enrollment->id,
                    ]
                );
            }
        }
    }

    public function test_attendance_recording_rolls_back_when_audit_fails(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->record(
                    $teacherUser,
                    $session,
                    $enrollment,
                    [
                        'attendance_status_id' =>
                        $status->id,
                    ]
                );

            $this->fail(
                'Expected simulated audit failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseMissing(
            'attendances',
            [
                'session_id' =>
                $session->id,

                'enrollment_id' =>
                $enrollment->id,
            ]
        );
    }

    public function test_attendance_update_rolls_back_when_audit_fails(): void
    {
        [
            $center,,
            $teacherUser,,
            $session,
            $enrollment,
            $status,
        ] = $this->teacherAttendanceContext();

        $this->establishCenterContext(
            $center
        );

        $attendance =
            $this->service()
            ->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $status->id,

                    'late_minutes' =>
                    0,

                    'notes' =>
                    null,
                ]
            );

        $this->bindFailingAudit();

        try {
            $this->service()
                ->update(
                    $teacherUser,
                    $attendance,
                    [
                        'late_minutes' =>
                        20,

                        'notes' =>
                        'Changed.',
                    ]
                );

            $this->fail(
                'Expected simulated audit failure.'
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage()
            );
        }

        $attendance->refresh();

        $this->assertSame(
            0,
            $attendance->late_minutes
        );

        $this->assertNull(
            $attendance->notes
        );
    }

    /**
     * @return array{
     *     Center,
     *     Branch,
     *     User,
     *     Teacher,
     *     ClassSession,
     *     Enrollment,
     *     AttendanceStatus
     * }
     */
    private function teacherAttendanceContext(
        ?Center $center = null,
        ?Branch $branch = null,
        ?User $teacherUser = null,
        ?Teacher $teacher = null
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create();

        $branch ??=
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        if ($teacherUser === null) {
            $teacherUser =
                $this->createUserForRole(
                    SystemRole::Teacher,
                    $center
                );
        }

        if ($teacher === null) {
            $person =
                Person::query()
                ->withoutGlobalScopes()
                ->findOrFail(
                    $teacherUser->person_id
                );

            $teacher =
                Teacher::factory()
                ->forPerson($person)
                ->active()
                ->create([
                    'user_id' =>
                    $teacherUser->id,
                ]);
        }

        $classroom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->forClassroom($classroom)
            ->forTeacher($teacher)
            ->active()
            ->create();

        $schedule =
            ClassSchedule::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create();

        $session =
            ClassSession::factory()
            ->forSchedule(
                $schedule
            )
            ->scheduled()
            ->create();

        $student =
            Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $branch->id,
            ]);

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'enrollment_date' =>
                $session
                    ->session_date
                    ->toDateString(),
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create();

        return [
            $center,
            $branch,
            $teacherUser,
            $teacher,
            $session,
            $enrollment,
            $status,
        ];
    }

    private function createTransferHistory(
        Enrollment $enrollment,
        CourseClass $targetClass,
        User $actor,
        mixed $occurredAt
    ): EnrollmentHistory {
        return EnrollmentHistory::query()
            ->withoutGlobalScopes()
            ->create([
                'center_id' =>
                $enrollment->center_id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                $enrollment->class_id,

                'to_class_id' =>
                $targetClass->id,

                'performed_by_user_id' =>
                $actor->id,

                'event_type' =>
                'transfer',

                'previous_status' =>
                EnrollmentStatus::Active,

                'new_status' =>
                EnrollmentStatus::Transferred,

                'notes' =>
                null,

                'occurred_at' =>
                $occurredAt,
            ]);
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
                now()->subDay(),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function bindFailingAudit(): void
    {
        $failingAudit =
            \Mockery::mock(
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

    private function service(): AttendanceManagementService
    {
        return app(
            AttendanceManagementService::class
        );
    }

    private function auditCount(
        string $actionType,
        Attendance $attendance
    ): int {
        return AuditRecord::query()
            ->where(
                'action_type',
                $actionType
            )
            ->where(
                'subject_type',
                $attendance->getTable()
            )
            ->where(
                'subject_id',
                $attendance->id
            )
            ->count();
    }

    private function createUserForRole(
        SystemRole $role,
        Center $center
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
}
