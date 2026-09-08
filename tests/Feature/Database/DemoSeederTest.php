<?php

namespace Tests\Feature\Database;

use App\Filament\Resources\AuditRecords\AuditRecordResource;
use App\Models\AcademicLevel;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditRecord;
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
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_does_not_create_demo_data(): void
    {
        $this->seed(
            DatabaseSeeder::class
        );

        $this->assertDatabaseMissing(
            'centers',
            [
                'code' =>
                DemoSeeder::CENTER_CODE,
            ]
        );
    }

    public function test_demo_seeder_creates_complete_uat_scenario(): void
    {
        $this->seed(
            DemoSeeder::class
        );

        $center =
            Center::withoutGlobalScopes()
            ->where(
                'code',
                DemoSeeder::CENTER_CODE
            )
            ->firstOrFail();

        $this->assertSame(
            DemoSeeder::CENTER_IDENTIFIER_CODE,
            $center->identifier_code
        );

        /*
         * ---------------------------------------------------------
         * CENTER / BRANCH STRUCTURE
         * ---------------------------------------------------------
         */

        $branchA =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'code',
                'DEMO-A'
            )
            ->firstOrFail();

        $branchB =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'code',
                'DEMO-B'
            )
            ->firstOrFail();

        $this->assertNotSame(
            $branchA->id,
            $branchB->id
        );

        $this->assertSame(
            2,
            Branch::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        /*
         * ---------------------------------------------------------
         * DEMO ACCOUNTS / CREDENTIALS
         * ---------------------------------------------------------
         */

        $platformOwner =
            $this->account(
                'platform.owner@demo.lcms.test'
            );

        $centerOwner =
            $this->account(
                'center.owner@demo.lcms.test'
            );

        $branchManager =
            $this->account(
                'branch.manager@demo.lcms.test'
            );

        $financeEmployee =
            $this->account(
                'finance.employee@demo.lcms.test'
            );

        $teacherAccount =
            $this->account(
                'teacher@demo.lcms.test'
            );

        $studentAccount =
            $this->account(
                'student.a@demo.lcms.test'
            );

        $this->assertSame(
            '00000001',
            $platformOwner
                ->account_login_identifier
        );

        $this->assertSame(
            '98100001',
            $centerOwner
                ->account_login_identifier
        );

        $this->assertSame(
            '98110001',
            $branchManager
                ->account_login_identifier
        );

        $this->assertSame(
            '98210001',
            $financeEmployee
                ->account_login_identifier
        );

        $this->assertSame(
            '98120001',
            $teacherAccount
                ->account_login_identifier
        );

        $this->assertSame(
            '98130001',
            $studentAccount
                ->account_login_identifier
        );

        foreach (
            [
                $platformOwner,
                $centerOwner,
                $branchManager,
                $financeEmployee,
                $teacherAccount,
                $studentAccount,
            ] as $account
        ) {
            $this->assertTrue(
                Hash::check(
                    DemoSeeder::DEMO_PASSWORD,
                    $account->password
                )
            );
        }

        /*
         * ---------------------------------------------------------
         * STAFF / ASSIGNMENTS
         * ---------------------------------------------------------
         */

        $this->assertSame(
            1,
            BranchManager::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            FinanceEmployee::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            Teacher::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            BranchManagerAssignment::withoutGlobalScopes()
                ->where(
                    'user_id',
                    $branchManager->id
                )
                ->where(
                    'branch_id',
                    $branchA->id
                )
                ->where(
                    'active_marker',
                    1
                )
                ->whereNull(
                    'ended_at'
                )
                ->count()
        );

        $this->assertSame(
            1,
            FinanceEmployeeAssignment::withoutGlobalScopes()
                ->where(
                    'user_id',
                    $financeEmployee->id
                )
                ->where(
                    'branch_id',
                    $branchA->id
                )
                ->where(
                    'active_marker',
                    1
                )
                ->whereNull(
                    'ended_at'
                )
                ->count()
        );

        /*
         * ---------------------------------------------------------
         * STUDENTS
         * ---------------------------------------------------------
         */

        $this->assertSame(
            2,
            Student::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $studentA =
            Student::withoutGlobalScopes()
            ->where(
                'user_id',
                $studentAccount->id
            )
            ->firstOrFail();

        $this->assertSame(
            $branchA->id,
            $studentA->branch_id
        );

        $this->assertSame(
            1,
            Student::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'branch_id',
                    $branchB->id
                )
                ->whereNull(
                    'user_id'
                )
                ->count()
        );

        /*
         * ---------------------------------------------------------
         * CLASSROOMS / ACADEMIC CATALOG
         * ---------------------------------------------------------
         */

        $this->assertSame(
            2,
            Classroom::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $language =
            Language::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'name',
                'English'
            )
            ->firstOrFail();

        $level =
            AcademicLevel::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'language_id',
                $language->id
            )
            ->where(
                'code',
                'A1'
            )
            ->firstOrFail();

        $course =
            Course::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'academic_level_id',
                $level->id
            )
            ->where(
                'code',
                'ENG-A1'
            )
            ->firstOrFail();

        $this->assertSame(
            $language->id,
            $course->language_id
        );

        /*
         * ---------------------------------------------------------
         * COURSE CLASSES / ENROLLMENTS
         * ---------------------------------------------------------
         */

        $this->assertSame(
            2,
            CourseClass::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            CourseClass::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'branch_id',
                    $branchA->id
                )
                ->where(
                    'class_code',
                    'DEMO-CLASS-A'
                )
                ->count()
        );

        $this->assertSame(
            1,
            CourseClass::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'branch_id',
                    $branchB->id
                )
                ->where(
                    'class_code',
                    'DEMO-CLASS-B'
                )
                ->count()
        );

        $this->assertSame(
            2,
            Enrollment::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        /*
 * ---------------------------------------------------------
 * SCHEDULING / SESSIONS
 * ---------------------------------------------------------
 */

        $this->assertSame(
            2,
            ClassSchedule::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            2,
            ClassSession::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        /*
 * ---------------------------------------------------------
 * ATTENDANCE
 * ---------------------------------------------------------
 */

        $this->assertSame(
            2,
            AttendanceStatus::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            Attendance::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        /*
         * ---------------------------------------------------------
         * AUDIT
         * ---------------------------------------------------------
         */

        $this->assertSame(
            4,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->count()
        );

        $this->assertSame(
            1,
            AuditRecord::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $center->id
                )
                ->where(
                    'branch_id',
                    $branchA->id
                )
                ->where(
                    'action_type',
                    'demo.payment_seeded'
                )
                ->count()
        );
    }

    public function test_demo_seeder_is_idempotent_across_complete_demo_state(): void
    {
        $this->seed(
            DemoSeeder::class
        );

        $before =
            $this->demoStateCounts();

        $this->seed(
            DemoSeeder::class
        );

        $after =
            $this->demoStateCounts();

        foreach (
            $before as
            $table => $count
        ) {
            $this->assertArrayHasKey(
                $table,
                $after
            );

            $this->assertSame(
                $count,
                $after[$table],
                'Table changed after second DemoSeeder run: '
                    . $table
            );
        }
    }

    public function test_branch_manager_demo_audit_scope_excludes_branch_b(): void
    {
        $this->seed(
            DemoSeeder::class
        );

        $center =
            Center::withoutGlobalScopes()
            ->where(
                'code',
                DemoSeeder::CENTER_CODE
            )
            ->firstOrFail();

        $branchA =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'code',
                'DEMO-A'
            )
            ->firstOrFail();

        $branchB =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $center->id
            )
            ->where(
                'code',
                'DEMO-B'
            )
            ->firstOrFail();

        $manager =
            $this->account(
                'branch.manager@demo.lcms.test'
            );

        $this->actingAs(
            $manager
        );

        app(
            TenantContext::class
        )->establishCenterScope(
            $center
        );

        app(
            BranchContext::class
        )->establishBranchScope(
            $branchA
        );

        $records =
            AuditRecordResource
            ::getEloquentQuery()
            ->get();

        $this->assertNotEmpty(
            $records
        );

        $this->assertTrue(
            $records->every(
                fn(
                    AuditRecord $record
                ): bool =>
                $record->branch_id
                    === $branchA->id
            )
        );

        $this->assertFalse(
            $records->contains(
                fn(
                    AuditRecord $record
                ): bool =>
                $record->branch_id
                    === $branchB->id
            )
        );
    }

    public function test_demo_seeder_refuses_production_environment(): void
    {
        $originalEnvironment =
            $this->app
            ->environment();

        $this->app
            ->detectEnvironment(
                fn(): string =>
                'production'
            );

        try {
            $this->expectException(
                LogicException::class
            );

            $this->expectExceptionMessage(
                'DemoSeeder cannot run in production.'
            );

            (new DemoSeeder())
                ->run();
        } finally {
            $this->app
                ->detectEnvironment(
                    fn(): string =>
                    $originalEnvironment
                );
        }
    }

    public function test_demo_seeder_refuses_incomplete_existing_demo_data(): void
    {
        $this->seed(
            DatabaseSeeder::class
        );

        Center::factory()
            ->active()
            ->create([
                'code' =>
                DemoSeeder::CENTER_CODE,

                'identifier_code' =>
                DemoSeeder::CENTER_IDENTIFIER_CODE,

                'name' =>
                'Incomplete Demo Center',
            ]);

        $this->expectException(
            LogicException::class
        );

        $this->expectExceptionMessage(
            'Incomplete LCMS demo data already exists. Refusing to modify it automatically.'
        );

        $this->seed(
            DemoSeeder::class
        );
    }

    public function test_demo_seeder_refuses_reserved_identifier_collision(): void
    {
        $this->seed(
            DatabaseSeeder::class
        );

        Center::factory()
            ->active()
            ->create([
                'code' =>
                'ANOTHER-CENTER',

                'identifier_code' =>
                DemoSeeder::CENTER_IDENTIFIER_CODE,

                'name' =>
                'Another Center',
            ]);

        $this->expectException(
            LogicException::class
        );

        $this->expectExceptionMessage(
            'Center identifier code 98 is already in use. Demo data was not created.'
        );

        $this->seed(
            DemoSeeder::class
        );
    }

    /**
     * Snapshot every table touched by DemoSeeder.
     *
     * Table names are resolved from the actual Eloquent models
     * instead of being duplicated manually in this test.
     *
     * @return array<string, int>
     */
    private function demoStateCounts(): array
    {
        $models = [
            Role::class,
            Center::class,
            Branch::class,
            Person::class,
            User::class,
            BranchManager::class,
            BranchManagerAssignment::class,
            FinanceEmployee::class,
            FinanceEmployeeAssignment::class,
            Teacher::class,
            Student::class,
            Classroom::class,
            Language::class,
            AcademicLevel::class,
            Course::class,
            CourseClass::class,
            Enrollment::class,
            ClassSchedule::class,
            ClassSession::class,
            AttendanceStatus::class,
            Attendance::class,
            EnrollmentFee::class,
            FeeInstallment::class,
            Payment::class,
            PaymentAllocation::class,
            AuditRecord::class,
        ];

        $counts = [];

        foreach (
            $models as $modelClass
        ) {
            $model =
                new $modelClass();

            $table =
                $model->getTable();

            $counts[$table] =
                DB::table(
                    $table
                )->count();
        }

        /*
         * AccountIdentifierSequence currently has no dedicated
         * model used by this test, but DemoSeeder changes its
         * state through account identifier generation.
         */
        $counts['account_identifier_sequences'] =
            DB::table(
                'account_identifier_sequences'
            )->count();

        ksort(
            $counts
        );

        return $counts;
    }

    private function account(
        string $email
    ): User {
        return User::withoutGlobalScopes()
            ->where(
                'recovery_email',
                $email
            )
            ->firstOrFail();
    }
}