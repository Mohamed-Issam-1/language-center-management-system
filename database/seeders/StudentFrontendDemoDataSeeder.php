<?php

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\Language;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class StudentFrontendDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'StudentFrontendDemoDataSeeder cannot run in production.'
            );
        }

        DB::transaction(function (): void {
            $user = User::withoutGlobalScopes()
                ->where(
                    'account_login_identifier',
                    '99000001'
                )
                ->first();

            if ($user === null) {
                throw new RuntimeException(
                    'Student account 99000001 was not found. Run StudentDashboardDemoSeeder first.'
                );
            }

            $student = Student::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->first();

            if ($student === null) {
                throw new RuntimeException(
                    'The demo User is not linked to a Student record.'
                );
            }

            $center = $student->center()
                ->withoutGlobalScopes()
                ->first();

            $branch = Branch::withoutGlobalScopes()
                ->whereKey($student->branch_id)
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->first();

            if ($center === null || $branch === null) {
                throw new RuntimeException(
                    'The demo Student requires a valid Center and Branch.'
                );
            }

            $operator = User::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'id',
                    '!=',
                    $user->id
                )
                ->orderBy('id')
                ->first()
                ?? $user;

            $currency = strtoupper(
                trim(
                    (string) (
                        $center->operating_currency_code
                        ?: 'USD'
                    )
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Teachers
            |--------------------------------------------------------------------------
            */

            $teacherOne = $this->teacher(
                centerId: $center->id,
                branchId: $branch->id,
                name: 'Mr. Hussam Al-Attar',
            );

            $teacherTwo = $this->teacher(
                centerId: $center->id,
                branchId: $branch->id,
                name: 'Ms. Leila Mansouri',
            );

            /*
            |--------------------------------------------------------------------------
            | Languages
            |--------------------------------------------------------------------------
            */

            $englishLanguage =
                Language::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'EN-DEMO',
                        ],
                        [
                            'name' =>
                                'English',

                            'description' =>
                                'English language demo record.',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            $frenchLanguage =
                Language::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'FR-DEMO',
                        ],
                        [
                            'name' =>
                                'French',

                            'description' =>
                                'French language demo record.',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | Levels
            |--------------------------------------------------------------------------
            */

            $englishLevel =
                AcademicLevel::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'language_id' =>
                                $englishLanguage->id,

                            'code' =>
                                'B2',
                        ],
                        [
                            'name' =>
                                'Intermediate',

                            'sequence_number' =>
                                4,

                            'description' =>
                                'B2 Intermediate demo level.',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            $frenchLevel =
                AcademicLevel::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'language_id' =>
                                $frenchLanguage->id,

                            'code' =>
                                'A1',
                        ],
                        [
                            'name' =>
                                'Beginner',

                            'sequence_number' =>
                                1,

                            'description' =>
                                'A1 Beginner demo level.',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | Courses
            |--------------------------------------------------------------------------
            */

            $englishCourse =
                Course::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'DEMO-ENG-B2',
                        ],
                        [
                            'language_id' =>
                                $englishLanguage->id,

                            'academic_level_id' =>
                                $englishLevel->id,

                            'name' =>
                                'English – Intermediate',

                            'description' =>
                                'Student frontend demo English course.',

                            'duration_weeks' =>
                                16,

                            'total_hours' =>
                                '48.00',

                            'default_fee' =>
                                '1800.00',

                            'passing_grade' =>
                                '60.00',

                            'minimum_attendance' =>
                                '75.00',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            $frenchCourse =
                Course::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'DEMO-FRE-A1',
                        ],
                        [
                            'language_id' =>
                                $frenchLanguage->id,

                            'academic_level_id' =>
                                $frenchLevel->id,

                            'name' =>
                                'French – Beginner',

                            'description' =>
                                'Student frontend demo French course.',

                            'duration_weeks' =>
                                12,

                            'total_hours' =>
                                '40.00',

                            'default_fee' =>
                                '1100.00',

                            'passing_grade' =>
                                '60.00',

                            'minimum_attendance' =>
                                '75.00',

                            'status' =>
                                AcademicRecordStatus::Active,

                            'archived_at' =>
                                null,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | Classrooms
            |--------------------------------------------------------------------------
            */

            $room204 =
                Classroom::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'branch_id' =>
                                $branch->id,

                            'code' =>
                                'DEMO-R204',
                        ],
                        [
                            'name' =>
                                'Room 204',

                            'capacity' =>
                                30,

                            'location' =>
                                'Second Floor',

                            'availability_status' =>
                                ClassroomAvailabilityStatus::Available,

                            'status' =>
                                ClassroomStatus::Active,
                        ]
                    );

            $room108 =
                Classroom::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'branch_id' =>
                                $branch->id,

                            'code' =>
                                'DEMO-R108',
                        ],
                        [
                            'name' =>
                                'Room 108',

                            'capacity' =>
                                30,

                            'location' =>
                                'First Floor',

                            'availability_status' =>
                                ClassroomAvailabilityStatus::Available,

                            'status' =>
                                ClassroomStatus::Active,
                        ]
                    );

            $deliveryMode =
                CourseClass::withoutGlobalScopes()
                    ->whereNotNull(
                        'delivery_mode'
                    )
                    ->value(
                        'delivery_mode'
                    )
                ?: 'onsite';

            /*
            |--------------------------------------------------------------------------
            | Classes
            |--------------------------------------------------------------------------
            */

            $englishClass =
                CourseClass::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'class_code' =>
                                'ENG-B2-03',
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'course_id' =>
                                $englishCourse->id,

                            'assigned_classroom_id' =>
                                $room204->id,

                            'assigned_teacher_id' =>
                                $teacherOne->id,

                            'name' =>
                                'English B2 - Section 03',

                            'start_date' =>
                                '2026-08-01',

                            'end_date' =>
                                '2026-12-20',

                            'capacity' =>
                                30,

                            'delivery_mode' =>
                                $deliveryMode,

                            'class_status' =>
                                CourseClassStatus::Active,
                        ]
                    );

            $frenchClass =
                CourseClass::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'class_code' =>
                                'FRE-A1-01',
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'course_id' =>
                                $frenchCourse->id,

                            'assigned_classroom_id' =>
                                $room108->id,

                            'assigned_teacher_id' =>
                                $teacherTwo->id,

                            'name' =>
                                'French A1 - Section 01',

                            'start_date' =>
                                '2026-08-15',

                            'end_date' =>
                                '2026-12-30',

                            'capacity' =>
                                30,

                            'delivery_mode' =>
                                $deliveryMode,

                            'class_status' =>
                                CourseClassStatus::Active,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | Weekly schedules
            |--------------------------------------------------------------------------
            */

            $englishMonday =
                $this->schedule(
                    $center->id,
                    $englishClass->id,
                    $room204->id,
                    $teacherOne->id,
                    1,
                    '17:00:00',
                    '19:00:00',
                    '2026-08-01',
                    '2026-12-20',
                );

            $englishWednesday =
                $this->schedule(
                    $center->id,
                    $englishClass->id,
                    $room204->id,
                    $teacherOne->id,
                    3,
                    '17:00:00',
                    '19:00:00',
                    '2026-08-01',
                    '2026-12-20',
                );

            $frenchTuesday =
                $this->schedule(
                    $center->id,
                    $frenchClass->id,
                    $room108->id,
                    $teacherTwo->id,
                    2,
                    '18:00:00',
                    '20:00:00',
                    '2026-08-15',
                    '2026-12-30',
                );

            $frenchThursday =
                $this->schedule(
                    $center->id,
                    $frenchClass->id,
                    $room108->id,
                    $teacherTwo->id,
                    4,
                    '18:00:00',
                    '20:00:00',
                    '2026-08-15',
                    '2026-12-30',
                );

            /*
            |--------------------------------------------------------------------------
            | Student enrollments
            |--------------------------------------------------------------------------
            */

            $englishEnrollment =
                Enrollment::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'enrollment_number' =>
                                'DEMO-STU-ENG-001',
                        ],
                        [
                            'student_id' =>
                                $student->id,

                            'class_id' =>
                                $englishClass->id,

                            'enrollment_date' =>
                                '2026-08-01',

                            'enrollment_status' =>
                                EnrollmentStatus::Active,

                            'eligibility_status' =>
                                'eligible',

                            'withdrawal_date' =>
                                null,

                            'withdrawal_reason' =>
                                null,
                        ]
                    );

            $frenchEnrollment =
                Enrollment::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'enrollment_number' =>
                                'DEMO-STU-FRE-001',
                        ],
                        [
                            'student_id' =>
                                $student->id,

                            'class_id' =>
                                $frenchClass->id,

                            'enrollment_date' =>
                                '2026-08-15',

                            'enrollment_status' =>
                                EnrollmentStatus::Active,

                            'eligibility_status' =>
                                'eligible',

                            'withdrawal_date' =>
                                null,

                            'withdrawal_reason' =>
                                null,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | Attendance statuses
            |--------------------------------------------------------------------------
            */

            $presentStatus =
                AttendanceStatus::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'present',
                        ],
                        [
                            'name' =>
                                'Present',

                            'contribution_value' =>
                                '1.00',

                            'is_active' =>
                                true,
                        ]
                    );

            $absentStatus =
                AttendanceStatus::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'absent',
                        ],
                        [
                            'name' =>
                                'Absent',

                            'contribution_value' =>
                                '0.00',

                            'is_active' =>
                                true,
                        ]
                    );

            $lateStatus =
                AttendanceStatus::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'code' =>
                                'late',
                        ],
                        [
                            'name' =>
                                'Late',

                            /*
                             * Late still counts as attended for
                             * the demo attendance percentage.
                             */
                            'contribution_value' =>
                                '1.00',

                            'is_active' =>
                                true,
                        ]
                    );

            /*
            |--------------------------------------------------------------------------
            | English sessions
            |--------------------------------------------------------------------------
            |
            | 6 Present + 1 Late + 1 Absent = 7/8 = 87.5% => 88%
            |
            */

            $englishSessions = [
                [
                    'date' => '2026-08-12',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-08-17',
                    'schedule' => $englishMonday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-08-19',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-08-24',
                    'schedule' => $englishMonday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-08-26',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'late',
                ],
                [
                    'date' => '2026-08-31',
                    'schedule' => $englishMonday,
                    'status' => ClassSessionStatus::Cancelled,
                    'attendance' => null,
                ],
                [
                    'date' => '2026-09-02',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-09-07',
                    'schedule' => $englishMonday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'absent',
                ],
                [
                    'date' => '2026-09-09',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Completed,
                    'attendance' => 'present',
                ],
                [
                    'date' => '2026-09-14',
                    'schedule' => $englishMonday,
                    'status' => ClassSessionStatus::Scheduled,
                    'attendance' => null,
                ],
                [
                    'date' => '2026-09-16',
                    'schedule' => $englishWednesday,
                    'status' => ClassSessionStatus::Scheduled,
                    'attendance' => null,
                ],
            ];

            foreach ($englishSessions as $definition) {
                $session =
                    $this->session(
                        centerId: $center->id,
                        classId: $englishClass->id,
                        schedule: $definition['schedule'],
                        classroomId: $room204->id,
                        teacherId: $teacherOne->id,
                        date: $definition['date'],
                        startTime: '17:00:00',
                        endTime: '19:00:00',
                        status: $definition['status'],
                    );

                $attendanceCode =
                    $definition['attendance'];

                if ($attendanceCode === null) {
                    continue;
                }

                $attendanceStatus =
                    match ($attendanceCode) {
                        'present' => $presentStatus,
                        'absent' => $absentStatus,
                        'late' => $lateStatus,
                    };

                $this->attendance(
                    centerId: $center->id,
                    sessionId: $session->id,
                    enrollmentId: $englishEnrollment->id,
                    statusId: $attendanceStatus->id,
                    operatorId: $operator->id,
                    status: $attendanceCode,
                    date: $definition['date'],
                );
            }

            /*
            |--------------------------------------------------------------------------
            | French sessions
            |--------------------------------------------------------------------------
            |
            | 19 Present + 1 Absent = 95%
            |
            */

            $frenchDates = [];

            $cursor =
                CarbonImmutable::parse(
                    '2026-06-30'
                );

            $lastPastFrenchDate =
                CarbonImmutable::parse(
                    '2026-09-08'
                );

            while (
                $cursor->lessThanOrEqualTo(
                    $lastPastFrenchDate
                )
            ) {
                if (
                    in_array(
                        $cursor->dayOfWeekIso,
                        [2, 4],
                        true
                    )
                ) {
                    $frenchDates[] =
                        $cursor;
                }

                $cursor =
                    $cursor->addDay();
            }

            $frenchDates =
                array_slice(
                    $frenchDates,
                    -20
                );

            foreach (
                $frenchDates as $index =>
                $date
            ) {
                $schedule =
                    $date->dayOfWeekIso === 2
                        ? $frenchTuesday
                        : $frenchThursday;

                $session =
                    $this->session(
                        centerId:
                            $center->id,

                        classId:
                            $frenchClass->id,

                        schedule:
                            $schedule,

                        classroomId:
                            $room108->id,

                        teacherId:
                            $teacherTwo->id,

                        date:
                            $date
                                ->toDateString(),

                        startTime:
                            '18:00:00',

                        endTime:
                            '20:00:00',

                        status:
                            ClassSessionStatus::Completed,
                    );

                $isAbsent =
                    $index === 5;

                $this->attendance(
                    centerId:
                        $center->id,

                    sessionId:
                        $session->id,

                    enrollmentId:
                        $frenchEnrollment->id,

                    statusId:
                        $isAbsent
                            ? $absentStatus->id
                            : $presentStatus->id,

                    operatorId:
                        $operator->id,

                    status:
                        $isAbsent
                            ? 'absent'
                            : 'present',

                    date:
                        $date
                            ->toDateString(),
                );
            }

            /*
             * Today: Thursday, Sep 10, 2026.
             */
            $this->session(
                centerId: $center->id,
                classId: $frenchClass->id,
                schedule: $frenchThursday,
                classroomId: $room108->id,
                teacherId: $teacherTwo->id,
                date: '2026-09-10',
                startTime: '18:00:00',
                endTime: '20:00:00',
                status: ClassSessionStatus::Scheduled,
            );

            $this->session(
                centerId: $center->id,
                classId: $frenchClass->id,
                schedule: $frenchTuesday,
                classroomId: $room108->id,
                teacherId: $teacherTwo->id,
                date: '2026-09-15',
                startTime: '18:00:00',
                endTime: '20:00:00',
                status: ClassSessionStatus::Scheduled,
            );

            /*
            |--------------------------------------------------------------------------
            | English finance
            |--------------------------------------------------------------------------
            */

            $englishFee =
                EnrollmentFee::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'enrollment_id' =>
                                $englishEnrollment->id,
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'amount' =>
                                '1800.00',

                            'currency_code' =>
                                $currency,

                            'status' =>
                                EnrollmentFeeStatus::Active,

                            'created_by_user_id' =>
                                $operator->id,

                            'voided_by_user_id' =>
                                null,

                            'voided_at' =>
                                null,

                            'void_reason' =>
                                null,
                        ]
                    );

            $englishInstallment1 =
                $this->installment(
                    $center->id,
                    $branch->id,
                    $englishFee->id,
                    1,
                    '2026-08-15',
                    '600.00',
                );

            $englishInstallment2 =
                $this->installment(
                    $center->id,
                    $branch->id,
                    $englishFee->id,
                    2,
                    '2026-09-01',
                    '600.00',
                );

            $this->installment(
                $center->id,
                $branch->id,
                $englishFee->id,
                3,
                '2026-09-05',
                '600.00',
            );

            $englishPayment =
                Payment::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'idempotency_key' =>
                                'DEMO-ENG-PAYMENT-001',
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'student_id' =>
                                $student->id,

                            'receipt_number' =>
                                'DEMO-ENG-REC-001',

                            'amount' =>
                                '1200.00',

                            'currency_code' =>
                                $currency,

                            'payment_method' =>
                                'cash',

                            'paid_at' =>
                                '2026-09-01 12:00:00',

                            'received_by_user_id' =>
                                $operator->id,

                            'reference' =>
                                'Student frontend demo',

                            'notes' =>
                                'Demo payment for Student frontend testing.',

                            'status' =>
                                PaymentStatus::Posted,

                            'reversed_at' =>
                                null,

                            'reversed_by_user_id' =>
                                null,

                            'reversal_reason' =>
                                null,
                        ]
                    );

            $this->allocation(
                $center->id,
                $branch->id,
                $englishPayment->id,
                $englishInstallment1->id,
                '600.00',
            );

            $this->allocation(
                $center->id,
                $branch->id,
                $englishPayment->id,
                $englishInstallment2->id,
                '600.00',
            );

            /*
            |--------------------------------------------------------------------------
            | French finance
            |--------------------------------------------------------------------------
            */

            $frenchFee =
                EnrollmentFee::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'enrollment_id' =>
                                $frenchEnrollment->id,
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'amount' =>
                                '1100.00',

                            'currency_code' =>
                                $currency,

                            'status' =>
                                EnrollmentFeeStatus::Active,

                            'created_by_user_id' =>
                                $operator->id,

                            'voided_by_user_id' =>
                                null,

                            'voided_at' =>
                                null,

                            'void_reason' =>
                                null,
                        ]
                    );

            $frenchInstallment1 =
                $this->installment(
                    $center->id,
                    $branch->id,
                    $frenchFee->id,
                    1,
                    '2026-08-20',
                    '700.00',
                );

            $this->installment(
                $center->id,
                $branch->id,
                $frenchFee->id,
                2,
                '2026-12-01',
                '400.00',
            );

            $frenchPayment =
                Payment::withoutGlobalScopes()
                    ->updateOrCreate(
                        [
                            'center_id' =>
                                $center->id,

                            'idempotency_key' =>
                                'DEMO-FRE-PAYMENT-001',
                        ],
                        [
                            'branch_id' =>
                                $branch->id,

                            'student_id' =>
                                $student->id,

                            'receipt_number' =>
                                'DEMO-FRE-REC-001',

                            'amount' =>
                                '700.00',

                            'currency_code' =>
                                $currency,

                            'payment_method' =>
                                'cash',

                            'paid_at' =>
                                '2026-08-20 12:00:00',

                            'received_by_user_id' =>
                                $operator->id,

                            'reference' =>
                                'Student frontend demo',

                            'notes' =>
                                'Demo French payment.',

                            'status' =>
                                PaymentStatus::Posted,

                            'reversed_at' =>
                                null,

                            'reversed_by_user_id' =>
                                null,

                            'reversal_reason' =>
                                null,
                        ]
                    );

            $this->allocation(
                $center->id,
                $branch->id,
                $frenchPayment->id,
                $frenchInstallment1->id,
                '700.00',
            );

            $this->command?->newLine();

            $this->command?->info(
                'Student frontend demo data created.'
            );

            $this->command?->line(
                'Login ID: 99000001'
            );

            $this->command?->line(
                'Password: Student@12345'
            );

            $this->command?->line(
                "English enrollment ID: {$englishEnrollment->id}"
            );

            $this->command?->line(
                "French enrollment ID: {$frenchEnrollment->id}"
            );

            $this->command?->line(
                "Currency: {$currency}"
            );
        });
    }

    private function teacher(
        int $centerId,
        int $branchId,
        string $name,
    ): Teacher {
        $existing =
            Teacher::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $centerId
                )
                ->get()
                ->first(
                    function (
                        Teacher $teacher
                    ) use (
                        $name
                    ): bool {
                        if (
                            method_exists(
                                $teacher,
                                'person'
                            )
                        ) {
                            $teacher
                                ->loadMissing(
                                    'person'
                                );

                            if (
                                $teacher
                                    ->person
                                    ?->full_name
                                === $name
                            ) {
                                return true;
                            }
                        }

                        return false;
                    }
                );

        if ($existing !== null) {
            return $existing;
        }

        $attributes = [
            'center_id' =>
                $centerId,
        ];

        if (
            Schema::hasColumn(
                'teachers',
                'branch_id'
            )
        ) {
            $attributes[
                'branch_id'
            ] = $branchId;
        }

        $teacher =
            Teacher::factory()
                ->create(
                    $attributes
                );

        if (
            method_exists(
                $teacher,
                'person'
            )
        ) {
            $teacher
                ->loadMissing(
                    'person'
                );

            if (
                $teacher->person
                !== null
            ) {
                $teacher
                    ->person
                    ->forceFill([
                        'center_id' =>
                            $centerId,

                        'full_name' =>
                            $name,
                    ])
                    ->save();
            }
        }

        return $teacher->refresh();
    }

    private function schedule(
        int $centerId,
        int $classId,
        int $classroomId,
        int $teacherId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        string $from,
        string $until,
    ): ClassSchedule {
        return ClassSchedule::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'center_id' =>
                        $centerId,

                    'class_id' =>
                        $classId,

                    'day_of_week' =>
                        $dayOfWeek,
                ],
                [
                    'classroom_id' =>
                        $classroomId,

                    'teacher_id' =>
                        $teacherId,

                    'start_time' =>
                        $startTime,

                    'end_time' =>
                        $endTime,

                    'effective_from' =>
                        $from,

                    'effective_until' =>
                        $until,

                    'status' =>
                        ClassScheduleStatus::Active,
                ]
            );
    }

    private function session(
        int $centerId,
        int $classId,
        ClassSchedule $schedule,
        int $classroomId,
        int $teacherId,
        string $date,
        string $startTime,
        string $endTime,
        ClassSessionStatus $status,
    ): ClassSession {
        return ClassSession::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'center_id' =>
                        $centerId,

                    'class_id' =>
                        $classId,

                    'occurrence_date' =>
                        $date,

                    'start_time' =>
                        $startTime,
                ],
                [
                    'schedule_id' =>
                        $schedule->id,

                    'classroom_id' =>
                        $classroomId,

                    'teacher_id' =>
                        $teacherId,

                    'session_date' =>
                        $date,

                    'end_time' =>
                        $endTime,

                    'topic' =>
                        'Demo lesson',

                    'session_status' =>
                        $status,

                    'cancellation_reason' =>
                        $status ===
                            ClassSessionStatus::Cancelled
                            ? 'Demo cancelled session'
                            : null,
                ]
            );
    }

    private function attendance(
        int $centerId,
        int $sessionId,
        int $enrollmentId,
        int $statusId,
        int $operatorId,
        string $status,
        string $date,
    ): void {
        Attendance::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'center_id' =>
                        $centerId,

                    'session_id' =>
                        $sessionId,

                    'enrollment_id' =>
                        $enrollmentId,
                ],
                [
                    'attendance_status_id' =>
                        $statusId,

                    'recorded_by_user_id' =>
                        $operatorId,

                    'late_minutes' =>
                        $status === 'late'
                            ? 10
                            : 0,

                    'excuse' =>
                        $status === 'absent'
                            ? 'No excuse submitted'
                            : null,

                    'notes' =>
                        null,

                    'recorded_at' =>
                        "{$date} 20:05:00",
                ]
            );
    }

    private function installment(
        int $centerId,
        int $branchId,
        int $feeId,
        int $sequence,
        string $dueDate,
        string $amount,
    ): FeeInstallment {
        return FeeInstallment::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'center_id' =>
                        $centerId,

                    'enrollment_fee_id' =>
                        $feeId,

                    'sequence_number' =>
                        $sequence,
                ],
                [
                    'branch_id' =>
                        $branchId,

                    'due_date' =>
                        $dueDate,

                    'amount' =>
                        $amount,
                ]
            );
    }

    private function allocation(
        int $centerId,
        int $branchId,
        int $paymentId,
        int $installmentId,
        string $amount,
    ): void {
        PaymentAllocation::withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'center_id' =>
                        $centerId,

                    'payment_id' =>
                        $paymentId,

                    'fee_installment_id' =>
                        $installmentId,
                ],
                [
                    'branch_id' =>
                        $branchId,

                    'amount' =>
                        $amount,
                ]
            );
    }
}