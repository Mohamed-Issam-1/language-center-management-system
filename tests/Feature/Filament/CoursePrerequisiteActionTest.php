<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CoursePrerequisiteActionTest
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

    public function test_center_owner_can_replace_complete_prerequisite_set_through_filament(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $level =
            $this->academicLevel(
                $center
            );

        $prerequisiteA =
            $this->course(
                $level,
                'Foundation Course'
            );

        $prerequisiteB =
            $this->course(
                $level,
                'Preparation Course'
            );

        $course =
            $this->course(
                $level,
                'Advanced Course'
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        Livewire::test(
            ListCourses::class
        )
            ->assertActionVisible(
                TestAction::make(
                    'managePrerequisites'
                )->table(
                    $course
                )
            )
            ->callAction(
                TestAction::make(
                    'managePrerequisites'
                )->table(
                    $course
                ),
                [
                    'prerequisites' => [
                        [
                            'course_id' =>
                            $prerequisiteA
                                ->id,

                            'requirement_type' =>
                            'completion',
                        ],
                        [
                            'course_id' =>
                            $prerequisiteB
                                ->id,

                            'requirement_type' =>
                            null,
                        ],
                    ],
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $prerequisiteA->id,

                'requirement_type' =>
                'completion',
            ]
        );

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $prerequisiteB->id,

                'requirement_type' =>
                null,
            ]
        );

        $this->assertSame(
            2,
            $course
                ->prerequisites()
                ->count()
        );
    }

    public function test_filament_replacement_removes_old_prerequisites_and_preserves_new_set(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $level =
            $this->academicLevel(
                $center
            );

        $oldPrerequisite =
            $this->course(
                $level,
                'Old Prerequisite'
            );

        $newPrerequisite =
            $this->course(
                $level,
                'New Prerequisite'
            );

        $course =
            $this->course(
                $level,
                'Target Course'
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        app(
            AcademicCatalogManagementService::class
        )->replacePrerequisites(
            $owner,
            $course,
            [
                [
                    'course_id' =>
                    $oldPrerequisite
                        ->id,

                    'requirement_type' =>
                    'old-rule',
                ],
            ]
        );

        Livewire::test(
            ListCourses::class
        )
            ->callAction(
                TestAction::make(
                    'managePrerequisites'
                )->table(
                    $course
                ),
                [
                    'prerequisites' => [
                        [
                            'course_id' =>
                            $newPrerequisite
                                ->id,

                            'requirement_type' =>
                            'new-rule',
                        ],
                    ],
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $oldPrerequisite->id,
            ]
        );

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $newPrerequisite->id,

                'requirement_type' =>
                'new-rule',
            ]
        );
    }

    public function test_empty_filament_prerequisite_set_removes_all_existing_prerequisites(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $level =
            $this->academicLevel(
                $center
            );

        $prerequisite =
            $this->course(
                $level,
                'Prerequisite'
            );

        $course =
            $this->course(
                $level,
                'Target'
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        app(
            AcademicCatalogManagementService::class
        )->replacePrerequisites(
            $owner,
            $course,
            [
                [
                    'course_id' =>
                    $prerequisite->id,

                    'requirement_type' =>
                    null,
                ],
            ]
        );

        Livewire::test(
            ListCourses::class
        )
            ->callAction(
                TestAction::make(
                    'managePrerequisites'
                )->table(
                    $course
                ),
                [
                    'prerequisites' => [],
                ]
            )
            ->assertHasNoActionErrors();

        $this->assertSame(
            0,
            $course
                ->prerequisites()
                ->count()
        );

        $this->assertDatabaseMissing(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $course->id,
            ]
        );
    }

    public function test_cycle_attempt_through_filament_is_rejected_and_existing_graph_is_preserved(): void
    {
        $center =
            Center::factory()
            ->active()
            ->create();

        $level =
            $this->academicLevel(
                $center
            );

        $courseA =
            $this->course(
                $level,
                'Course A'
            );

        $courseB =
            $this->course(
                $level,
                'Course B'
            );

        $owner =
            $this->centerOwner(
                $center
            );

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $center
        );

        /*
         * B requires A:
         *
         * B -> A
         */
        app(
            AcademicCatalogManagementService::class
        )->replacePrerequisites(
            $owner,
            $courseB,
            [
                [
                    'course_id' =>
                    $courseA->id,

                    'requirement_type' =>
                    null,
                ],
            ]
        );

        /*
         * Attempt:
         *
         * A -> B
         *
         * This would produce:
         *
         * A -> B -> A
         */
        Livewire::test(
            ListCourses::class
        )
            ->callAction(
                TestAction::make(
                    'managePrerequisites'
                )->table(
                    $courseA
                ),
                [
                    'prerequisites' => [
                        [
                            'course_id' =>
                            $courseB->id,

                            'requirement_type' =>
                            null,
                        ],
                    ],
                ]
            )
            ->assertHasNoActionErrors();

        /*
         * New cyclic edge must not survive.
         */
        $this->assertDatabaseMissing(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $courseA->id,

                'prerequisite_course_id' =>
                $courseB->id,
            ]
        );

        /*
         * Existing valid graph must remain intact.
         */
        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'center_id' =>
                $center->id,

                'course_id' =>
                $courseB->id,

                'prerequisite_course_id' =>
                $courseA->id,
            ]
        );
    }

    public function test_prerequisite_options_are_center_scoped_and_exclude_current_course(): void
    {
        $centerA =
            Center::factory()
            ->active()
            ->create();

        $centerB =
            Center::factory()
            ->active()
            ->create();

        $levelA =
            $this->academicLevel(
                $centerA
            );

        $levelB =
            $this->academicLevel(
                $centerB
            );

        $course =
            $this->course(
                $levelA,
                'Target Course'
            );

        $sameCenterCandidate =
            $this->course(
                $levelA,
                'Same Center Candidate'
            );

        $otherCenterCandidate =
            $this->course(
                $levelB,
                'Other Center Candidate'
            );

        $owner =
            $this->centerOwner(
                $centerA
            );

        $this->actingAs(
            $owner
        );

        $this->establishContext(
            $centerA
        );

        $options =
            CourseResource
            ::prerequisiteCourseOptions(
                $course
            );

        $this->assertArrayHasKey(
            $sameCenterCandidate->id,
            $options
        );

        $this->assertArrayNotHasKey(
            $course->id,
            $options
        );

        $this->assertArrayNotHasKey(
            $otherCenterCandidate->id,
            $options
        );
    }

    private function academicLevel(
        Center $center
    ): AcademicLevel {
        $language =
            Language::factory()
            ->for($center)
            ->active()
            ->create();

        return AcademicLevel::factory()
            ->forLanguage(
                $language
            )
            ->active()
            ->create();
    }

    private function course(
        AcademicLevel $level,
        string $name
    ): Course {
        return Course::factory()
            ->forAcademicLevel(
                $level
            )
            ->create([
                'name' => $name,
            ]);
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
