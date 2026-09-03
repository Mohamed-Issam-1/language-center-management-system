<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\Branch;
use App\Models\Center;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\EnrollmentHistory;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EnrollmentHistoryViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_enrollment_view_displays_read_only_history(): void
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

        $owner =
            $this->createCenterUser(
                SystemRole::CenterOwner,
                $center
            );

        $student =
            Student::factory()
            ->forBranch($branch)
            ->active()
            ->create();

        $courseClass =
            CourseClass::factory()
            ->forBranch($branch)
            ->planned()
            ->create();

        $enrollment =
            Enrollment::factory()
            ->forStudent($student)
            ->forCourseClass(
                $courseClass
            )
            ->completed()
            ->create();

        EnrollmentHistory::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $center->id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                $courseClass->id,

                'performed_by_user_id' =>
                $owner->id,

                'event_type' =>
                'created',

                'previous_status' =>
                null,

                'new_status' =>
                EnrollmentStatus::Active,

                'notes' =>
                null,

                'occurred_at' =>
                now()->subMinute(),
            ]);

        EnrollmentHistory::withoutGlobalScopes()
            ->create([
                'center_id' =>
                $center->id,

                'enrollment_id' =>
                $enrollment->id,

                'from_class_id' =>
                null,

                'to_class_id' =>
                null,

                'performed_by_user_id' =>
                $owner->id,

                'event_type' =>
                'completed',

                'previous_status' =>
                EnrollmentStatus::Active,

                'new_status' =>
                EnrollmentStatus::Completed,

                'notes' =>
                'Completion confirmed.',

                'occurred_at' =>
                now(),
            ]);

        $this->actingAs(
            $owner
        );

        $this->establishCenterOwnerContext(
            $center
        );

        Livewire::test(
            ViewEnrollment::class,
            [
                'record' =>
                $enrollment
                    ->getRouteKey(),
            ]
        )
            ->assertSuccessful()
            ->assertSee(
                'Enrollment History'
            )
            ->assertSee('Created')
            ->assertSee('Completed')
            ->assertSee('Active')
            ->assertSee(
                $owner
                    ->account_login_identifier
            )
            ->assertSee(
                'Completion confirmed.'
            );
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

    private function createCenterUser(
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

                'must_change_password' =>
                false,
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