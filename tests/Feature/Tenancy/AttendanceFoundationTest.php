<?php

namespace Tests\Feature\Tenancy;

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Center;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_status_stores_required_fields_and_casts(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,

                'is_active' =>
                true,
            ]);

        $this->assertSame(
            $center->id,
            $status->center_id
        );

        $this->assertSame(
            'Present',
            $status->name
        );

        $this->assertSame(
            'PRESENT',
            $status->code
        );

        $this->assertSame(
            '100.00',
            $status->contribution_value
        );

        $this->assertTrue(
            $status->is_active
        );

        $this->assertTrue(
            $status->isActive()
        );

        $this->assertTrue(
            $status->center
                ->is($center)
        );

        $this->assertTrue(
            $center
                ->attendanceStatuses
                ->contains($status)
        );
    }

    public function test_new_attendance_status_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $status =
            AttendanceStatus::query()
            ->create([
                'center_id' =>
                $center->id,

                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

        $status->refresh();

        $this->assertTrue(
            $status->is_active
        );

        $this->assertTrue(
            $status->isActive()
        );
    }

    public function test_attendance_stores_required_relationships_and_casts(): void
    {
        [
            $center,
            $session,
            $enrollment,
            $status,
            $recorder,
        ] = $this->validAttendanceContext();

        $recordedAt =
            now()
            ->startOfSecond();

        $attendance =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create([
                'late_minutes' =>
                12,

                'excuse' =>
                'Transportation delay.',

                'notes' =>
                'Arrived after lesson started.',

                'recorded_at' =>
                $recordedAt,
            ]);

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
            $recorder->id,
            $attendance
                ->recorded_by_user_id
        );

        $this->assertSame(
            12,
            $attendance->late_minutes
        );

        $this->assertSame(
            'Transportation delay.',
            $attendance->excuse
        );

        $this->assertSame(
            'Arrived after lesson started.',
            $attendance->notes
        );

        $this->assertSame(
            $recordedAt->toDateTimeString(),
            $attendance
                ->recorded_at
                ->toDateTimeString()
        );

        $this->assertNotNull(
            $attendance->updated_at
        );

        $this->assertTrue(
            $attendance->center
                ->is($center)
        );

        $this->assertTrue(
            $attendance->session
                ->is($session)
        );

        $this->assertTrue(
            $attendance->enrollment
                ->is($enrollment)
        );

        $this->assertTrue(
            $attendance
                ->attendanceStatus
                ->is($status)
        );

        $this->assertTrue(
            $attendance
                ->recordedBy
                ->is($recorder)
        );

        $this->assertTrue(
            $center->attendances
                ->contains($attendance)
        );

        $this->assertTrue(
            $session->attendances
                ->contains($attendance)
        );

        $this->assertTrue(
            $enrollment->attendances
                ->contains($attendance)
        );

        $this->assertTrue(
            $status->attendances
                ->contains($attendance)
        );
    }

    public function test_attendance_status_code_must_be_unique_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        AttendanceStatus::factory()
            ->forCenter($center)
            ->create([
                'code' =>
                'PRESENT',
            ]);

        $this->expectException(
            QueryException::class
        );

        AttendanceStatus::factory()
            ->forCenter($center)
            ->create([
                'code' =>
                'PRESENT',
            ]);
    }

    public function test_same_attendance_status_code_is_allowed_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $statusA =
            AttendanceStatus::factory()
            ->forCenter($centerA)
            ->create([
                'code' =>
                'PRESENT',
            ]);

        $statusB =
            AttendanceStatus::factory()
            ->forCenter($centerB)
            ->create([
                'code' =>
                'PRESENT',
            ]);

        $this->assertSame(
            'PRESENT',
            $statusA->code
        );

        $this->assertSame(
            'PRESENT',
            $statusB->code
        );

        $this->assertNotSame(
            $statusA->center_id,
            $statusB->center_id
        );
    }

    public function test_database_rejects_session_from_another_center(): void
    {
        [
            $centerA,,
            $enrollmentA,
            $statusA,
            $recorderA,
        ] = $this->validAttendanceContext();

        [,
            $sessionB,
        ] = $this->validAttendanceContext();

        $this->expectException(
            QueryException::class
        );

        Attendance::withoutGlobalScopes()
            ->create(
                $this->attendanceAttributes(
                    centerId: $centerA->id,
                    sessionId: $sessionB->id,
                    enrollmentId: $enrollmentA->id,
                    statusId: $statusA->id,
                    recorderId: $recorderA->id
                )
            );
    }

    public function test_database_rejects_enrollment_from_another_center(): void
    {
        [
            $centerA,
            $sessionA,,
            $statusA,
            $recorderA,
        ] = $this->validAttendanceContext();

        [,,
            $enrollmentB,
        ] = $this->validAttendanceContext();

        $this->expectException(
            QueryException::class
        );

        Attendance::withoutGlobalScopes()
            ->create(
                $this->attendanceAttributes(
                    centerId: $centerA->id,
                    sessionId: $sessionA->id,
                    enrollmentId: $enrollmentB->id,
                    statusId: $statusA->id,
                    recorderId: $recorderA->id
                )
            );
    }

    public function test_database_rejects_attendance_status_from_another_center(): void
    {
        [
            $centerA,
            $sessionA,
            $enrollmentA,,
            $recorderA,
        ] = $this->validAttendanceContext();

        [,,,
            $statusB,
        ] = $this->validAttendanceContext();

        $this->expectException(
            QueryException::class
        );

        Attendance::withoutGlobalScopes()
            ->create(
                $this->attendanceAttributes(
                    centerId: $centerA->id,
                    sessionId: $sessionA->id,
                    enrollmentId: $enrollmentA->id,
                    statusId: $statusB->id,
                    recorderId: $recorderA->id
                )
            );
    }

    public function test_database_rejects_recorder_from_another_center(): void
    {
        [
            $centerA,
            $sessionA,
            $enrollmentA,
            $statusA,
        ] = $this->validAttendanceContext();

        [,,,,
            $recorderB,
        ] = $this->validAttendanceContext();

        $this->expectException(
            QueryException::class
        );

        Attendance::withoutGlobalScopes()
            ->create(
                $this->attendanceAttributes(
                    centerId: $centerA->id,
                    sessionId: $sessionA->id,
                    enrollmentId: $enrollmentA->id,
                    statusId: $statusA->id,
                    recorderId: $recorderB->id
                )
            );
    }

    public function test_same_session_and_enrollment_cannot_have_duplicate_attendance(): void
    {
        [,
            $session,
            $enrollment,
            $status,
            $recorder,
        ] = $this->validAttendanceContext();

        Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();

        $secondStatus =
            AttendanceStatus::factory()
            ->create([
                'center_id' =>
                $session->center_id,
            ]);

        $this->expectException(
            QueryException::class
        );

        Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollment)
            ->withStatus($secondStatus)
            ->recordedBy($recorder)
            ->create();
    }

    public function test_different_enrollments_can_have_attendance_for_same_session(): void
    {
        [
            $center,
            $session,
            $enrollmentA,
            $status,
            $recorder,
        ] = $this->validAttendanceContext();

        $studentB =
            \App\Models\Student::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'branch_id' =>
                $enrollmentA
                    ->student
                    ->branch_id,
            ]);

        $enrollmentB =
            Enrollment::factory()
            ->forStudent($studentB)
            ->forCourseClass(
                $enrollmentA->courseClass
            )
            ->active()
            ->create([
                'enrollment_date' =>
                $session
                    ->session_date
                    ->toDateString(),
            ]);

        $attendanceA =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollmentA)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();

        $attendanceB =
            Attendance::factory()
            ->forSession($session)
            ->forEnrollment($enrollmentB)
            ->withStatus($status)
            ->recordedBy($recorder)
            ->create();

        $this->assertNotSame(
            $attendanceA->enrollment_id,
            $attendanceB->enrollment_id
        );

        $this->assertSame(
            $attendanceA->session_id,
            $attendanceB->session_id
        );
    }

    public function test_attendance_status_and_attendance_center_scope_exclude_other_centers(): void
    {
        [
            $centerA,
            $sessionA,
            $enrollmentA,
            $statusA,
            $recorderA,
        ] = $this->validAttendanceContext();

        $attendanceA =
            Attendance::factory()
            ->forSession($sessionA)
            ->forEnrollment($enrollmentA)
            ->withStatus($statusA)
            ->recordedBy($recorderA)
            ->create();

        [,
            $sessionB,
            $enrollmentB,
            $statusB,
            $recorderB,
        ] = $this->validAttendanceContext();

        $attendanceB =
            Attendance::factory()
            ->forSession($sessionB)
            ->forEnrollment($enrollmentB)
            ->withStatus($statusB)
            ->recordedBy($recorderB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $statusIds =
            AttendanceStatus::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $attendanceIds =
            Attendance::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $statusA->id,
            $statusIds
        );

        $this->assertNotContains(
            $statusB->id,
            $statusIds
        );

        $this->assertContains(
            $attendanceA->id,
            $attendanceIds
        );

        $this->assertNotContains(
            $attendanceB->id,
            $attendanceIds
        );
    }

    /**
     * @return array{
     *     Center,
     *     ClassSession,
     *     Enrollment,
     *     AttendanceStatus,
     *     User
     * }
     */
    private function validAttendanceContext(): array
    {
        $center = Center::factory()
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        /*
     * A Class Session must inherit its Class, Teacher,
     * Classroom, and Center from one valid Schedule.
     *
     * Creating the Schedule explicitly prevents the Session
     * fixture from overriding class_id independently of its
     * parent Schedule.
     */
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

        $enrollment =
            Enrollment::factory()
            ->forCourseClass(
                $courseClass
            )
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'enrollment_date' =>
                $session
                    ->session_date
                    ->toDateString(),
            ]);

        $status =
            AttendanceStatus::factory()
            ->forCenter($center)
            ->active()
            ->create([
                'name' =>
                'Present',

                'code' =>
                fake()
                    ->unique()
                    ->bothify(
                        'PRESENT-####'
                    ),

                'contribution_value' =>
                100,
            ]);

        $recorder =
            $this->createCenterOwner(
                $center
            );

        return [
            $center,
            $session,
            $enrollment,
            $status,
            $recorder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceAttributes(
        int $centerId,
        int $sessionId,
        int $enrollmentId,
        int $statusId,
        int $recorderId
    ): array {
        return [
            'center_id' =>
            $centerId,

            'session_id' =>
            $sessionId,

            'enrollment_id' =>
            $enrollmentId,

            'attendance_status_id' =>
            $statusId,

            'recorded_by_user_id' =>
            $recorderId,

            'late_minutes' =>
            0,

            'excuse' =>
            null,

            'notes' =>
            null,

            'recorded_at' =>
            now(),
        ];
    }

    private function createCenterOwner(
        Center $center
    ): User {
        $person = Person::factory()
            ->for($center)
            ->create();

        $role = Role::query()
            ->firstOrCreate(
                [
                    'code' =>
                    SystemRole::CenterOwner
                        ->value,
                ],
                [
                    'name' =>
                    SystemRole::CenterOwner
                        ->label(),
                ]
            );

        return User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $role->id,
            ]);
    }
}
