<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CourseClasses\Pages\ListCourseClasses;
use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Person;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CourseClassLifecycleActionTest
extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_planned_class_exposes_activate_and_cancel_but_not_complete(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->courseClass(
            CourseClassStatus::Planned
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourseClasses::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'activateCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'cancelCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertActionHidden(
                TestAction::make(
                    'completeCourseClass'
                )->table(
                    $courseClass
                )
            );
    }

    public function test_center_owner_can_activate_planned_course_class_through_filament(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->courseClass(
            CourseClassStatus::Planned
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourseClasses::class
        )
            ->callAction(
                TestAction::make(
                    'activateCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'course_classes',
            [
                'id' =>
                $courseClass->id,

                'class_status' =>
                CourseClassStatus
                ::Active
                    ->value,
            ]
        );
    }

    public function test_active_class_exposes_complete_and_cancel_but_not_activate(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->courseClass(
            CourseClassStatus::Active
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourseClasses::class
        )
            ->assertActionHidden(
                TestAction::make(
                    'activateCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'completeCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertActionVisible(
                TestAction::make(
                    'cancelCourseClass'
                )->table(
                    $courseClass
                )
            );
    }

    public function test_center_owner_can_complete_active_course_class_through_filament(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->courseClass(
            CourseClassStatus::Active
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourseClasses::class
        )
            ->callAction(
                TestAction::make(
                    'completeCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'course_classes',
            [
                'id' =>
                $courseClass->id,

                'class_status' =>
                CourseClassStatus
                ::Completed
                    ->value,
            ]
        );
    }

    public function test_center_owner_can_cancel_planned_course_class_through_filament(): void
    {
        [
            $center,
            $courseClass,
        ] = $this->courseClass(
            CourseClassStatus::Planned
        );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourseClasses::class
        )
            ->callAction(
                TestAction::make(
                    'cancelCourseClass'
                )->table(
                    $courseClass
                )
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'course_classes',
            [
                'id' =>
                $courseClass->id,

                'class_status' =>
                CourseClassStatus
                ::Cancelled
                    ->value,
            ]
        );
    }

    public function test_completed_and_cancelled_classes_expose_no_lifecycle_transitions(): void
    {
        [
            $center,
            $completedClass,
        ] = $this->courseClass(
            CourseClassStatus::Completed
        );

        [, $cancelledClass] =
            $this->courseClass(
                CourseClassStatus::Cancelled,
                $center
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs($owner);

        $this->establishContext(
            $center
        );

        foreach (
            [
                $completedClass,
                $cancelledClass,
            ] as $courseClass
        ) {
            Livewire::test(
                ListCourseClasses::class
            )
                ->assertActionHidden(
                    TestAction::make(
                        'activateCourseClass'
                    )->table(
                        $courseClass
                    )
                )
                ->assertActionHidden(
                    TestAction::make(
                        'completeCourseClass'
                    )->table(
                        $courseClass
                    )
                )
                ->assertActionHidden(
                    TestAction::make(
                        'cancelCourseClass'
                    )->table(
                        $courseClass
                    )
                );
        }
    }

    /**
     * @return array{
     *     0: Center,
     *     1: CourseClass
     * }
     */
    private function courseClass(
        CourseClassStatus $status,
        ?Center $center = null
    ): array {
        $center ??=
            Center::factory()
            ->active()
            ->create();

        $branch =
            Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $course =
            Course::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom =
            Classroom::factory()
            ->forBranch(
                $branch
            )
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

        $factory =
            CourseClass::factory()
            ->forBranch(
                $branch
            )
            ->forCourse(
                $course
            )
            ->forClassroom(
                $classroom
            )
            ->forTeacher(
                $teacher
            )
            ->create([
                'capacity' => 20,

                'start_date' =>
                '2026-09-01',

                'end_date' =>
                '2026-10-31',

                'class_status' =>
                $status,
            ]);

        return [
            $center,
            $factory,
        ];
    }

    private function centerOwner(
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
                    SystemRole::CenterOwner
                )->id,

                'status' =>
                AccountStatus::Active,

                'must_change_password' =>
                false,
            ]);
    }

    private function establishContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();
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
