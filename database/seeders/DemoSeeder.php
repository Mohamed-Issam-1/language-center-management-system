<?php

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\BranchManager;
use App\Models\BranchManagerAssignment;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\FinanceEmployee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Language;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoSeeder extends Seeder
{
    public const CENTER_CODE =
    'LCMS-DEMO';

    public const CENTER_IDENTIFIER_CODE =
    '98';

    public const DEMO_PASSWORD =
    'DemoPass123!';

    private const DEMO_EMAILS = [
        'platform.owner@demo.lcms.test',
        'center.owner@demo.lcms.test',
        'branch.manager@demo.lcms.test',
        'finance.employee@demo.lcms.test',
        'teacher@demo.lcms.test',
        'student.a@demo.lcms.test',
    ];

    public function run(): void
    {
        if (
            app()->environment(
                'production'
            )
        ) {
            throw new LogicException(
                'DemoSeeder cannot run in production.'
            );
        }

        $this->call(
            RoleSeeder::class
        );

        $existingCenter =
            Center::withoutGlobalScopes()
            ->where(
                'code',
                self::CENTER_CODE
            )
            ->first();

        if (
            $existingCenter !== null
        ) {
            $this->ensureExistingDemoIsComplete(
                $existingCenter
            );

            $this->command?->info(
                'LCMS demo data already exists. Nothing was changed.'
            );

            return;
        }

        $identifierIsUsed =
            Center::withoutGlobalScopes()
            ->where(
                'identifier_code',
                self::CENTER_IDENTIFIER_CODE
            )
            ->exists();

        if ($identifierIsUsed) {
            throw new LogicException(
                'Center identifier code 98 is already in use. Demo data was not created.'
            );
        }

        $accounts =
            DB::transaction(
                fn(): array =>
                $this->createScenario()
            );

        $this->showCredentials(
            $accounts
        );
    }

    /**
     * @return array<string, User>
     */
    private function createScenario(): array
    {
        app(
            BranchContext::class
        )->clear();

        $platformOwner =
            $this->createAccount(
                role: SystemRole::PlatformOwner,

                center: null,

                person: null,

                name: 'Demo Platform Owner',

                email: 'platform.owner@demo.lcms.test'
            );

        $center =
            Center::factory()
            ->active()
            ->create([
                'code' =>
                self::CENTER_CODE,

                'identifier_code' =>
                self::CENTER_IDENTIFIER_CODE,

                'name' =>
                'LCMS Demo Language Center',

                'email' =>
                'center@demo.lcms.test',

                'phone' =>
                '+970590000000',

                'address' =>
                'Demo Address - Gaza',

                'timezone' =>
                'Asia/Gaza',

                'operating_currency_code' =>
                'USD',
            ]);

        $workingHours = [
            'monday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],

            'tuesday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],

            'wednesday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],

            'thursday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],

            'friday' => [
                'opens_at' =>
                '08:00',

                'closes_at' =>
                '18:00',
            ],
        ];

        $branchA =
            Branch::factory()
            ->for($center)
            ->active()
            ->create([
                'name' =>
                'Demo Branch A',

                'code' =>
                'DEMO-A',

                'phone' =>
                '+970590000010',

                'email' =>
                'branch.a@demo.lcms.test',

                'address' =>
                'Demo Branch A Address',

                'working_hours' =>
                $workingHours,
            ]);

        $branchB =
            Branch::factory()
            ->for($center)
            ->active()
            ->create([
                'name' =>
                'Demo Branch B',

                'code' =>
                'DEMO-B',

                'phone' =>
                '+970590000020',

                'email' =>
                'branch.b@demo.lcms.test',

                'address' =>
                'Demo Branch B Address',

                'working_hours' =>
                $workingHours,
            ]);

        $ownerPerson =
            $this->createPerson(
                center: $center,

                nationalId: '980000001',

                name: 'Demo Center Owner',

                email: 'center.owner@demo.lcms.test',

                phone: '+970590000001',

                birthDate: '1985-01-10'
            );

        $centerOwner =
            $this->createAccount(
                role: SystemRole::CenterOwner,

                center: $center,

                person: $ownerPerson,

                name: 'Demo Center Owner',

                email: 'center.owner@demo.lcms.test'
            );

        $managerPerson =
            $this->createPerson(
                center: $center,

                nationalId: '980000002',

                name: 'Demo Branch Manager',

                email: 'branch.manager@demo.lcms.test',

                phone: '+970590000002',

                birthDate: '1988-02-15'
            );

        $branchManagerAccount =
            $this->createAccount(
                role: SystemRole::BranchManager,

                center: $center,

                person: $managerPerson,

                name: 'Demo Branch Manager',

                email: 'branch.manager@demo.lcms.test'
            );

        BranchManager::factory()
            ->forPerson(
                $managerPerson
            )
            ->active()
            ->create([
                'user_id' =>
                $branchManagerAccount->id,
            ]);

        BranchManagerAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $branchManagerAccount->id,

                'branch_id' =>
                $branchA->id,

                'started_at' =>
                now()
                    ->subDays(30),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $financePerson =
            $this->createPerson(
                center: $center,

                nationalId: '980000003',

                name: 'Demo Finance Employee',

                email: 'finance.employee@demo.lcms.test',

                phone: '+970590000003',

                birthDate: '1990-03-20'
            );

        $financeAccount =
            $this->createAccount(
                role: SystemRole::FinanceEmployee,

                center: $center,

                person: $financePerson,

                name: 'Demo Finance Employee',

                email: 'finance.employee@demo.lcms.test'
            );

        FinanceEmployee::factory()
            ->forPerson(
                $financePerson
            )
            ->active()
            ->create([
                'user_id' =>
                $financeAccount->id,
            ]);

        FinanceEmployeeAssignment::query()
            ->create([
                'center_id' =>
                $center->id,

                'user_id' =>
                $financeAccount->id,

                'branch_id' =>
                $branchA->id,

                'started_at' =>
                now()
                    ->subDays(30),

                'ended_at' =>
                null,

                'active_marker' =>
                1,
            ]);

        $teacherPerson =
            $this->createPerson(
                center: $center,

                nationalId: '980000004',

                name: 'Demo Teacher',

                email: 'teacher@demo.lcms.test',

                phone: '+970590000004',

                birthDate: '1992-04-25'
            );

        $teacherAccount =
            $this->createAccount(
                role: SystemRole::Teacher,

                center: $center,

                person: $teacherPerson,

                name: 'Demo Teacher',

                email: 'teacher@demo.lcms.test'
            );

        $teacher =
            Teacher::factory()
            ->forPerson(
                $teacherPerson
            )
            ->active()
            ->create([
                'user_id' =>
                $teacherAccount->id,
            ]);

        $studentPersonA =
            $this->createPerson(
                center: $center,

                nationalId: '980000005',

                name: 'Demo Student A',

                email: 'student.a@demo.lcms.test',

                phone: '+970590000005',

                birthDate: '2002-05-10'
            );

        $studentAccountA =
            $this->createAccount(
                role: SystemRole::Student,

                center: $center,

                person: $studentPersonA,

                name: 'Demo Student A',

                email: 'student.a@demo.lcms.test'
            );

        $studentA =
            Student::factory()
            ->forBranch(
                $branchA
            )
            ->forPerson(
                $studentPersonA
            )
            ->active()
            ->create([
                'user_id' =>
                $studentAccountA->id,
            ]);

        $studentPersonB =
            $this->createPerson(
                center: $center,

                nationalId: '980000006',

                name: 'Demo Student B',

                email: 'student.b@demo.lcms.test',

                phone: '+970590000006',

                birthDate: '2003-06-12'
            );

        $studentB =
            Student::factory()
            ->forBranch(
                $branchB
            )
            ->forPerson(
                $studentPersonB
            )
            ->active()
            ->create([
                'user_id' =>
                null,
            ]);

        $roomA =
            Classroom::factory()
            ->forBranch(
                $branchA
            )
            ->active()
            ->available()
            ->create([
                'name' =>
                'Demo Classroom A',

                'code' =>
                'DEMO-ROOM-A',

                'capacity' =>
                20,

                'location' =>
                'Branch A - Room 1',
            ]);

        $roomB =
            Classroom::factory()
            ->forBranch(
                $branchB
            )
            ->active()
            ->available()
            ->create([
                'name' =>
                'Demo Classroom B',

                'code' =>
                'DEMO-ROOM-B',

                'capacity' =>
                20,

                'location' =>
                'Branch B - Room 1',
            ]);

        $language =
            Language::factory()
            ->active()
            ->create([
                'center_id' =>
                $center->id,

                'name' =>
                'English',

                'code' =>
                'EN',

                'description' =>
                'English language demo catalog.',
            ]);

        $level =
            AcademicLevel::factory()
            ->forLanguage(
                $language
            )
            ->active()
            ->create([
                'name' =>
                'Beginner A1',

                'code' =>
                'A1',

                'sequence_number' =>
                1,

                'description' =>
                'Demo beginner academic level.',
            ]);

        $course =
            Course::factory()
            ->forAcademicLevel(
                $level
            )
            ->active()
            ->create([
                'name' =>
                'English A1',

                'code' =>
                'ENG-A1',

                'description' =>
                'Demo English A1 course.',

                'duration_weeks' =>
                4,

                'total_hours' =>
                16,

                'default_fee' =>
                300,

                'passing_grade' =>
                60,

                'minimum_attendance' =>
                75,
            ]);

        $classStart =
            now()
            ->startOfWeek()
            ->startOfDay();

        $classEnd =
            $classStart
            ->copy()
            ->addDays(28);

        $classA =
            CourseClass::factory()
            ->forBranch(
                $branchA
            )
            ->forCourse(
                $course
            )
            ->forClassroom(
                $roomA
            )
            ->forTeacher(
                $teacher
            )
            ->active()
            ->create([
                'class_code' =>
                'DEMO-CLASS-A',

                'name' =>
                'Demo English A1 - Branch A',

                'start_date' =>
                $classStart
                    ->toDateString(),

                'end_date' =>
                $classEnd
                    ->toDateString(),

                'capacity' =>
                20,

                'delivery_mode' =>
                'in_person',
            ]);

        $classB =
            CourseClass::factory()
            ->forBranch(
                $branchB
            )
            ->forCourse(
                $course
            )
            ->forClassroom(
                $roomB
            )
            ->forTeacher(
                $teacher
            )
            ->active()
            ->create([
                'class_code' =>
                'DEMO-CLASS-B',

                'name' =>
                'Demo English A1 - Branch B',

                'start_date' =>
                $classStart
                    ->toDateString(),

                'end_date' =>
                $classEnd
                    ->toDateString(),

                'capacity' =>
                20,

                'delivery_mode' =>
                'in_person',
            ]);

        $enrollmentA =
            Enrollment::factory()
            ->forStudent(
                $studentA
            )
            ->forCourseClass(
                $classA
            )
            ->active()
            ->create([
                'enrollment_number' =>
                'DEMO-ENR-A-001',

                'enrollment_date' =>
                $classStart
                    ->copy()
                    ->subDays(7)
                    ->toDateString(),

                'eligibility_status' =>
                'eligible',
            ]);

        $enrollmentB =
            Enrollment::factory()
            ->forStudent(
                $studentB
            )
            ->forCourseClass(
                $classB
            )
            ->active()
            ->create([
                'enrollment_number' =>
                'DEMO-ENR-B-001',

                'enrollment_date' =>
                $classStart
                    ->copy()
                    ->subDays(7)
                    ->toDateString(),

                'eligibility_status' =>
                'eligible',
            ]);

        $scheduleA =
            ClassSchedule::factory()
            ->forCourseClass(
                $classA
            )
            ->active()
            ->create([
                'day_of_week' =>
                1,

                'start_time' =>
                '09:00:00',

                'end_time' =>
                '10:30:00',

                'effective_from' =>
                $classStart
                    ->toDateString(),

                'effective_until' =>
                $classEnd
                    ->toDateString(),
            ]);

        $scheduleB =
            ClassSchedule::factory()
            ->forCourseClass(
                $classB
            )
            ->active()
            ->create([
                'day_of_week' =>
                2,

                'start_time' =>
                '11:00:00',

                'end_time' =>
                '12:30:00',

                'effective_from' =>
                $classStart
                    ->toDateString(),

                'effective_until' =>
                $classEnd
                    ->toDateString(),
            ]);

        $sessionA =
            ClassSession::factory()
            ->forSchedule(
                $scheduleA
            )
            ->completed()
            ->create([
                'session_date' =>
                $classStart
                    ->toDateString(),

                'occurrence_date' =>
                $classStart
                    ->toDateString(),

                'topic' =>
                'Introductions and basic vocabulary',
            ]);

        ClassSession::factory()
            ->forSchedule(
                $scheduleB
            )
            ->scheduled()
            ->create([
                'session_date' =>
                $classStart
                    ->copy()
                    ->addDays(8)
                    ->toDateString(),

                'occurrence_date' =>
                $classStart
                    ->copy()
                    ->addDays(8)
                    ->toDateString(),

                'topic' =>
                'Basic grammar',
            ]);

        $presentStatus =
            AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                'Present',

                'code' =>
                'PRESENT',

                'contribution_value' =>
                100,
            ]);

        AttendanceStatus::factory()
            ->forCenter(
                $center
            )
            ->active()
            ->create([
                'name' =>
                'Absent',

                'code' =>
                'ABSENT',

                'contribution_value' =>
                0,
            ]);

        Attendance::factory()
            ->forSession(
                $sessionA
            )
            ->forEnrollment(
                $enrollmentA
            )
            ->withStatus(
                $presentStatus
            )
            ->recordedBy(
                $teacherAccount
            )
            ->create([
                'late_minutes' =>
                0,

                'excuse' =>
                null,

                'notes' =>
                'Demo attendance record.',

                'recorded_at' =>
                $classStart
                    ->copy()
                    ->setTime(
                        10,
                        35
                    ),
            ]);

        $feeA =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollmentA
            )
            ->createdBy(
                $financeAccount
            )
            ->active()
            ->create([
                'amount' =>
                300,

                'currency_code' =>
                'USD',
            ]);

        $installmentA1 =
            FeeInstallment::factory()
            ->forEnrollmentFee(
                $feeA
            )
            ->withSequenceNumber(
                1
            )
            ->create([
                'amount' =>
                150,

                'due_date' =>
                $classStart
                    ->copy()
                    ->addDays(7)
                    ->toDateString(),
            ]);

        FeeInstallment::factory()
            ->forEnrollmentFee(
                $feeA
            )
            ->withSequenceNumber(
                2
            )
            ->create([
                'amount' =>
                150,

                'due_date' =>
                $classStart
                    ->copy()
                    ->addDays(21)
                    ->toDateString(),
            ]);

        $paymentA =
            Payment::factory()
            ->forBranch(
                $branchA
            )
            ->forStudent(
                $studentA
            )
            ->receivedBy(
                $financeAccount
            )
            ->posted()
            ->create([
                'receipt_number' =>
                'DEMO-RCT-0001',

                'idempotency_key' =>
                'demo-payment-branch-a-001',

                'amount' =>
                100,

                'currency_code' =>
                'USD',

                'payment_method' =>
                'cash',

                'paid_at' =>
                now()
                    ->subDay(),

                'reference' =>
                'DEMO-PAYMENT-A',

                'notes' =>
                'Partial demo payment.',
            ]);

        PaymentAllocation::factory()
            ->forPayment(
                $paymentA
            )
            ->forFeeInstallment(
                $installmentA1
            )
            ->create([
                'amount' =>
                100,
            ]);

        $feeB =
            EnrollmentFee::factory()
            ->forEnrollment(
                $enrollmentB
            )
            ->createdBy(
                $centerOwner
            )
            ->active()
            ->create([
                'amount' =>
                200,

                'currency_code' =>
                'USD',
            ]);

        FeeInstallment::factory()
            ->forEnrollmentFee(
                $feeB
            )
            ->withSequenceNumber(
                1
            )
            ->create([
                'amount' =>
                200,

                'due_date' =>
                $classStart
                    ->copy()
                    ->addDays(14)
                    ->toDateString(),
            ]);

        $tenant =
            app(
                TenantContext::class
            );

        $audit =
            app(
                AuditRecorder::class
            );

        app(
            BranchContext::class
        )->clear();

        $tenant
            ->establishPlatformScope();

        $audit->record(
            actor: $platformOwner,

            actionType: 'demo.center_seeded',

            subject: $center,

            metadata: [
                'source' =>
                'DemoSeeder',
            ]
        );

        $tenant
            ->establishCenterScope(
                $center
            );

        $audit->record(
            actor: $centerOwner,

            actionType: 'demo.branch_seeded',

            subject: $branchA,

            metadata: [
                'scenario' =>
                'branch-a',
            ]
        );

        $audit->record(
            actor: $centerOwner,

            actionType: 'demo.branch_seeded',

            subject: $branchB,

            metadata: [
                'scenario' =>
                'branch-b-isolation',
            ]
        );

        $audit->record(
            actor: $financeAccount,

            actionType: 'demo.payment_seeded',

            subject: $paymentA,

            metadata: [
                'scenario' =>
                'partial-payment',
            ]
        );

        return [
            'Platform Owner' =>
            $platformOwner,

            'Center Owner' =>
            $centerOwner,

            'Branch Manager' =>
            $branchManagerAccount,

            'Finance Employee' =>
            $financeAccount,

            'Teacher' =>
            $teacherAccount,

            'Student' =>
            $studentAccountA,
        ];
    }

    private function createPerson(
        Center $center,
        string $nationalId,
        string $name,
        string $email,
        string $phone,
        string $birthDate
    ): Person {
        return Person::factory()
            ->for(
                $center
            )
            ->create([
                'national_id_number' =>
                $nationalId,

                'full_name' =>
                $name,

                'date_of_birth' =>
                $birthDate,

                'city_of_residence' =>
                'Gaza',

                'email' =>
                $email,

                'phone_number' =>
                $phone,

                'personal_picture_path' =>
                null,
            ]);
    }

    private function createAccount(
        SystemRole $role,
        ?Center $center,
        ?Person $person,
        string $name,
        string $email
    ): User {
        $identifier =
            app(
                AccountIdentifierGenerator::class
            )->generate(
                $center,
                $role
            );

        return User::factory()
            ->create([
                'name' =>
                $name,

                'email' =>
                $email,

                'center_id' =>
                $center?->id,

                'person_id' =>
                $person?->id,

                'role_id' =>
                $this->role(
                    $role
                )->id,

                'account_login_identifier' =>
                $identifier,

                'recovery_email' =>
                $email,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,

                'password' =>
                self::DEMO_PASSWORD,
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

    private function ensureExistingDemoIsComplete(
        Center $center
    ): void {
        $branchCount =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->whereIn(
                'code',
                [
                    'DEMO-A',
                    'DEMO-B',
                ]
            )
            ->count();

        $accountCount =
            User::withoutGlobalScopes()
            ->whereIn(
                'recovery_email',
                self::DEMO_EMAILS
            )
            ->count();

        if (
            $branchCount !== 2
            || $accountCount !== 6
        ) {
            throw new LogicException(
                'Incomplete LCMS demo data already exists. Refusing to modify it automatically.'
            );
        }
    }

    /**
     * @param array<string, User> $accounts
     */
    private function showCredentials(
        array $accounts
    ): void {
        if (
            $this->command === null
        ) {
            return;
        }

        $this->command->info(
            'LCMS demo/UAT data created successfully.'
        );

        $this->command->newLine();

        foreach (
            $accounts as
            $role => $account
        ) {
            $this->command->line(
                $role
                    . ': '
                    . $account
                    ->account_login_identifier
                    . ' / '
                    . self::DEMO_PASSWORD
            );
        }

        $this->command->newLine();

        $this->command->warn(
            'These credentials are for local/demo/UAT environments only.'
        );
    }
}