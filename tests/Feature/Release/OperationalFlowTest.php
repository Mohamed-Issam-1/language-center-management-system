<?php

namespace Tests\Feature\Release;

use App\Models\AcademicLevel;
use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\FeeInstallment;
use App\Models\Language;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Attendance\AttendanceManagementService;
use App\Services\Attendance\AttendanceStatusManagementService;
use App\Services\CourseClasses\CourseClassManagementService;
use App\Services\Enrollment\EnrollmentManagementService;
use App\Services\Finance\FinanceManagementService;
use App\Services\Registration\RegistrationApprovalService;
use App\Services\Registration\RegistrationReviewService;
use App\Services\Registration\RegistrationSubmissionService;
use App\Services\Reports\DashboardReadService;
use App\Services\Reports\ReportReadService;
use App\Services\Scheduling\ScheduleManagementService;
use App\Services\Scheduling\SessionManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_core_operational_flow_from_registration_to_reporting(): void
    {
        /*
         * ---------------------------------------------------------
         * 1. BASE OPERATIONAL STRUCTURE
         * ---------------------------------------------------------
         */

        $center =
            Center::factory()
            ->active()
            ->create([
                'identifier_code' =>
                '41',

                'operating_currency_code' =>
                'USD',
            ]);

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create([
                'working_hours' => [
                    'monday' => [
                        'opens_at' =>
                        '08:00',

                        'closes_at' =>
                        '18:00',
                    ],
                ],
            ]);

        $ownerPerson =
            Person::factory()
            ->for($center)
            ->create();

        $owner =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $ownerPerson->id,

                'role_id' =>
                $this->role(
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,
            ]);

        $teacherPerson =
            Person::factory()
            ->for($center)
            ->create();

        $teacherUser =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $teacherPerson->id,

                'role_id' =>
                $this->role(
                    SystemRole::Teacher
                )->id,

                'status' =>
                AccountStatus::Active,
            ]);

        $teacher =
            Teacher::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $teacherPerson->id,

                'user_id' =>
                $teacherUser->id,
            ]);

        $classroom =
            Classroom::factory()
            ->forBranch($branch)
            ->active()
            ->available()
            ->create([
                'capacity' =>
                30,
            ]);

        $language =
            Language::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $level =
            AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create([
                'center_id' =>
                $center->id,
            ]);

        $course =
            Course::factory()
            ->forLanguage($language)
            ->forAcademicLevel($level)
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'default_fee' =>
                '300.00',
            ]);

        /*
         * ---------------------------------------------------------
         * 2. PUBLIC REGISTRATION SUBMISSION
         * ---------------------------------------------------------
         */

        $registration =
            app(
                RegistrationSubmissionService::class
            )->submit(
                $center,
                [
                    'national_id_number' =>
                    '900000001',

                    'full_name' =>
                    'Release Flow Student',

                    'date_of_birth' =>
                    '2002-04-15',

                    'city_of_residence' =>
                    'Gaza',

                    'email' =>
                    'release.student@example.test',

                    'phone_number' =>
                    '+970599000001',
                ]
            );

        $this->assertTrue(
            $registration->exists
        );

        $this->assertNull(
            $registration->selected_role_id
        );

        $this->assertNull(
            $registration->selected_branch_id
        );

        /*
         * ---------------------------------------------------------
         * 3. ADMINISTRATIVE REVIEW
         * ---------------------------------------------------------
         */

        $this->establishCenterOwnerContext(
            $center
        );

        $registration =
            app(
                RegistrationReviewService::class
            )->selectRole(
                $owner,
                $registration,
                SystemRole::Student
            );

        $registration =
            app(
                RegistrationReviewService::class
            )->selectBranch(
                $owner,
                $registration,
                $branch
            );

        $this->assertSame(
            $this->role(
                SystemRole::Student
            )->id,
            $registration->selected_role_id
        );

        $this->assertSame(
            $branch->id,
            $registration->selected_branch_id
        );

        /*
         * ---------------------------------------------------------
         * 4. REGISTRATION APPROVAL
         * ---------------------------------------------------------
         */

        $approval =
            app(
                RegistrationApprovalService::class
            )->approve(
                $owner,
                $registration
            );

        $student =
            Student::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'person_id',
                $approval->person->id
            )
            ->firstOrFail();

        $this->assertSame(
            SystemRole::Student,
            $approval->account
                ->systemRole()
        );

        $this->assertSame(
            $branch->id,
            $student->branch_id
        );

        $this->assertSame(
            $approval->account->id,
            $student->user_id
        );

        $this->assertSame(
            'release.student@example.test',
            $approval->recipientEmail
        );

        $this->assertNotSame(
            '',
            trim(
                $approval->temporaryPassword
            )
        );

        /*
         * ---------------------------------------------------------
         * 5. COURSE CLASS CREATION
         * ---------------------------------------------------------
         */

        $courseClass =
            app(
                CourseClassManagementService::class
            )->create(
                $owner,
                $branch,
                $course,
                $classroom,
                $teacher,
                [
                    'class_code' =>
                    'REL-A1-001',

                    'name' =>
                    'Release English A1',

                    'start_date' =>
                    '2026-09-01',

                    'end_date' =>
                    '2026-10-31',

                    'capacity' =>
                    20,

                    'delivery_mode' =>
                    'onsite',
                ]
            );

        /*
         * Scheduling requires an Active Course Class.
         */
        $courseClass =
            app(
                CourseClassManagementService::class
            )->activate(
                $owner,
                $courseClass
            );

        /*
         * ---------------------------------------------------------
         * 6. STUDENT ENROLLMENT
         * ---------------------------------------------------------
         */

        $enrollment =
            app(
                EnrollmentManagementService::class
            )->enroll(
                $owner,
                $student,
                $courseClass,
                [
                    'enrollment_number' =>
                    'REL-ENR-001',

                    'enrollment_date' =>
                    '2026-09-05',
                ]
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
            'eligible',
            $enrollment->eligibility_status
        );

        /*
         * ---------------------------------------------------------
         * 7. SCHEDULE
         * ---------------------------------------------------------
         */

        $schedule =
            app(
                ScheduleManagementService::class
            )->create(
                $owner,
                $courseClass,
                $classroom,
                $teacher,
                [
                    'day_of_week' =>
                    1,

                    'start_time' =>
                    '09:00',

                    'end_time' =>
                    '10:30',

                    'effective_from' =>
                    '2026-09-01',

                    'effective_until' =>
                    '2026-09-30',
                ]
            );

        $this->assertSame(
            $courseClass->id,
            $schedule->class_id
        );

        /*
         * ---------------------------------------------------------
         * 8. SESSION GENERATION
         * ---------------------------------------------------------
         */

        $sessions =
            app(
                SessionManagementService::class
            )->generateForSchedule(
                $owner,
                $schedule
            );

        /*
         * Mondays in September 2026:
         * 07, 14, 21, 28.
         */
        $this->assertCount(
            4,
            $sessions
        );

        $session =
            $sessions->first();

        $this->assertNotNull(
            $session
        );

        /*
         * ---------------------------------------------------------
         * 9. ATTENDANCE STATUS CONFIGURATION
         * ---------------------------------------------------------
         */

        $this->establishCenterOwnerContext(
            $center
        );

        $presentStatus =
            app(
                AttendanceStatusManagementService::class
            )->create(
                $owner,
                [
                    'name' =>
                    'Present',

                    'code' =>
                    'PRESENT',

                    'contribution_value' =>
                    '100',
                ]
            );

        /*
         * ---------------------------------------------------------
         * 10. ATTENDANCE RECORDING
         * ---------------------------------------------------------
         *
         * Teacher operations are center-scoped and do not inherit
         * the Center Owner's center-wide Branch context.
         */

        app(
            BranchContext::class
        )->clear();

        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        $attendance =
            app(
                AttendanceManagementService::class
            )->record(
                $teacherUser,
                $session,
                $enrollment,
                [
                    'attendance_status_id' =>
                    $presentStatus->id,

                    'late_minutes' =>
                    0,

                    'notes' =>
                    'Release operational flow attendance.',
                ]
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
            $teacherUser->id,
            $attendance->recorded_by_user_id
        );

        /*
         * ---------------------------------------------------------
         * 11. FINANCIAL OBLIGATION
         * ---------------------------------------------------------
         */

        $this->establishCenterOwnerContext(
            $center
        );

        $fee =
            app(
                FinanceManagementService::class
            )->createFee(
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
                            '2026-09-15',
                        ],
                        [
                            'amount' =>
                            '150.00',

                            'due_date' =>
                            '2026-10-15',
                        ],
                    ],
                ]
            );

        $installments =
            FeeInstallment::withoutGlobalScopes()
            ->where(
                'enrollment_fee_id',
                $fee->id
            )
            ->orderBy(
                'sequence_number'
            )
            ->get();

        $this->assertCount(
            2,
            $installments
        );

        /*
         * ---------------------------------------------------------
         * 12. PAYMENT POSTING
         * ---------------------------------------------------------
         */

        $payment =
            app(
                FinanceManagementService::class
            )->postPayment(
                $owner,
                $student,
                $branch,
                [
                    'idempotency_key' =>
                    'release-flow-payment-001',

                    'amount' =>
                    '100.00',

                    'payment_method' =>
                    'cash',

                    'paid_at' =>
                    '2026-09-05 12:00:00',

                    'reference' =>
                    'REL-PAY-001',

                    'notes' =>
                    'Release operational flow payment.',

                    'allocations' => [
                        [
                            'fee_installment_id' =>
                            $installments
                                ->first()
                                ->id,

                            'amount' =>
                            '100.00',
                        ],
                    ],
                ]
            );

        $this->assertSame(
            '100.00',
            $payment->amount
        );

        /*
         * ---------------------------------------------------------
         * 13. AUDIT RECORDS CREATED BY BUSINESS MUTATIONS
         * ---------------------------------------------------------
         */

        foreach (
            [
                'registration_request.approved',
                'course_class.created',
                'course_class.activated',
                'enrollment.created',
                'class_schedule.created',
                'class_session.generated',
                'attendance.recorded',
                'finance.fee_created',
                'finance.payment_posted',
            ] as $actionType
        ) {
            $this->assertDatabaseHas(
                'audit_records',
                [
                    'action_type' =>
                    $actionType,
                ]
            );
        }

        /*
         * ---------------------------------------------------------
         * 14. REPORTING / DASHBOARD READ CONTRACT
         * ---------------------------------------------------------
         *
         * Reads must not create new Audit Records.
         */

        $this->establishCenterOwnerContext(
            $center
        );

        $auditCountBeforeReads =
            AuditRecord::withoutGlobalScopes()
            ->count();

        $enrollmentSummary =
            app(
                ReportReadService::class
            )->enrollmentSummary(
                $owner
            );

        $attendanceSummary =
            app(
                ReportReadService::class
            )->attendanceSummary(
                $owner
            );

        $financeSummary =
            app(
                ReportReadService::class
            )->financeSummary(
                $owner
            );

        $dashboard =
            app(
                DashboardReadService::class
            )->forUser(
                $owner
            );

        $auditCountAfterReads =
            AuditRecord::withoutGlobalScopes()
            ->count();

        /*
         * ---------------------------------------------------------
         * 15. REPORT RESULT ASSERTIONS
         * ---------------------------------------------------------
         */

        $this->assertSame(
            1,
            $enrollmentSummary['total']
        );

        $this->assertSame(
            'center',
            $attendanceSummary['scope']['scope_type']
        );

        $this->assertSame(
            'center',
            $financeSummary['scope']['scope_type']
        );

        $this->assertSame(
            'operational',
            $financeSummary['report_type']
        );

        $this->assertSame(
            $center->id,
            $financeSummary['data']['center_id']
        );

        $this->assertSame(
            'center',
            $dashboard['scope']['scope_type']
        );

        $this->assertNotNull(
            $dashboard['enrollments']
        );

        $this->assertNotNull(
            $dashboard['classes']
        );

        $this->assertNotNull(
            $dashboard['attendance']
        );

        $this->assertNotNull(
            $dashboard['finance']
        );

        /*
         * Critical reporting contract:
         *
         * Dashboard and Report reads are not business mutations.
         */
        $this->assertSame(
            $auditCountBeforeReads,
            $auditCountAfterReads
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

    private function establishCenterOwnerContext(
        Center $center
    ): void {
        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->establishCenterWideScope();
    }
}