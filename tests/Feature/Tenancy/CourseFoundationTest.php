<?php

namespace Tests\Feature\Tenancy;

use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create([
                'name' => 'English A1',
                'code' => 'ENG-A1',
                'description' =>
                'English beginner course.',
                'duration_weeks' => 8,
                'total_hours' => 40.50,
                'default_fee' => 250.00,
                'passing_grade' => 60.00,
                'minimum_attendance' => 75.00,
            ]);

        $this->assertSame(
            $center->id,
            $course->center_id
        );

        $this->assertSame(
            $language->id,
            $course->language_id
        );

        $this->assertSame(
            $level->id,
            $course->academic_level_id
        );

        $this->assertSame(
            'English A1',
            $course->name
        );

        $this->assertSame(
            'ENG-A1',
            $course->code
        );

        $this->assertSame(
            8,
            $course->duration_weeks
        );

        $this->assertSame(
            '40.50',
            $course->total_hours
        );

        $this->assertSame(
            '250.00',
            $course->default_fee
        );

        $this->assertSame(
            '60.00',
            $course->passing_grade
        );

        $this->assertSame(
            '75.00',
            $course->minimum_attendance
        );
    }

    public function test_new_course_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $course = Course::query()
            ->create([
                'center_id' => $center->id,
                'language_id' => $language->id,
                'academic_level_id' => $level->id,
                'name' => 'English A1',
                'code' => 'ENG-A1',
                'duration_weeks' => 8,
                'total_hours' => 40,
                'default_fee' => 250,
                'passing_grade' => 60,
                'minimum_attendance' => 75,
            ]);

        $course->refresh();

        $this->assertSame(
            AcademicRecordStatus::Active,
            $course->status
        );

        $this->assertTrue(
            $course->isActive()
        );

        $this->assertFalse(
            $course->isArchived()
        );

        $this->assertNull(
            $course->archived_at
        );
    }

    public function test_course_casts_archived_lifecycle_state(): void
    {
        $course = Course::factory()
            ->archived()
            ->create();

        $this->assertSame(
            AcademicRecordStatus::Archived,
            $course->status
        );

        $this->assertTrue(
            $course->isArchived()
        );

        $this->assertFalse(
            $course->isActive()
        );

        $this->assertNotNull(
            $course->archived_at
        );
    }

    public function test_course_belongs_to_center_language_and_academic_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->assertTrue(
            $course->center->is(
                $center
            )
        );

        $this->assertTrue(
            $course->language->is(
                $language
            )
        );

        $this->assertTrue(
            $course->academicLevel->is(
                $level
            )
        );

        $this->assertTrue(
            $center->courses
                ->contains($course)
        );

        $this->assertTrue(
            $language->courses
                ->contains($course)
        );

        $this->assertTrue(
            $level->courses
                ->contains($course)
        );
    }

    public function test_database_rejects_course_language_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Course::query()
            ->create([
                'center_id' => $centerA->id,
                'language_id' => $languageB->id,
                'academic_level_id' => $levelB->id,
                'name' => 'Invalid Course',
                'code' => 'INVALID-1',
                'duration_weeks' => 8,
                'total_hours' => 40,
                'default_fee' => 100,
                'passing_grade' => 60,
                'minimum_attendance' => 75,
            ]);
    }

    public function test_database_rejects_academic_level_from_different_language(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $languageA = Language::factory()
            ->for($center)
            ->create();

        $languageB = Language::factory()
            ->for($center)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        Course::query()
            ->create([
                'center_id' => $center->id,
                'language_id' => $languageA->id,
                'academic_level_id' => $levelB->id,
                'name' => 'Invalid Course',
                'code' => 'INVALID-2',
                'duration_weeks' => 8,
                'total_hours' => 40,
                'default_fee' => 100,
                'passing_grade' => 60,
                'minimum_attendance' => 75,
            ]);
    }

    public function test_course_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $languageA = Language::factory()
            ->for($centerA)
            ->create();

        $levelA = AcademicLevel::factory()
            ->forLanguage($languageA)
            ->create();

        $courseA = Course::factory()
            ->forAcademicLevel($levelA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($levelB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = Course::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $courseA->id,
            $ids
        );

        $this->assertNotContains(
            $courseB->id,
            $ids
        );
    }

    public function test_course_can_have_prerequisite_and_inverse_relationship(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $prerequisite = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $course->prerequisites()
            ->attach(
                $prerequisite->id,
                [
                    'center_id' => $center->id,
                    'requirement_type' =>
                    'completion',
                ]
            );

        $course->unsetRelation(
            'prerequisites'
        );

        $prerequisite->unsetRelation(
            'requiredByCourses'
        );

        $this->assertTrue(
            $course->prerequisites
                ->contains($prerequisite)
        );

        $this->assertTrue(
            $prerequisite
                ->requiredByCourses
                ->contains($course)
        );

        $pivot = $course
            ->prerequisites
            ->firstOrFail()
            ->pivot;

        $this->assertSame(
            $center->id,
            $pivot->center_id
        );

        $this->assertSame(
            'completion',
            $pivot->requirement_type
        );
    }

    public function test_database_rejects_cross_center_prerequisite(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $languageA = Language::factory()
            ->for($centerA)
            ->create();

        $levelA = AcademicLevel::factory()
            ->forLanguage($languageA)
            ->create();

        $courseA = Course::factory()
            ->forAcademicLevel($levelA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($levelB)
            ->create();

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'course_prerequisites'
        )->insert([
            'center_id' => $centerA->id,
            'course_id' => $courseA->id,
            'prerequisite_course_id' =>
            $courseB->id,
            'requirement_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_duplicate_prerequisite_pair(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $prerequisite = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $values = [
            'center_id' => $center->id,
            'course_id' => $course->id,
            'prerequisite_course_id' =>
            $prerequisite->id,
            'requirement_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table(
            'course_prerequisites'
        )->insert($values);

        $this->expectException(
            QueryException::class
        );

        DB::table(
            'course_prerequisites'
        )->insert($values);
    }
}
