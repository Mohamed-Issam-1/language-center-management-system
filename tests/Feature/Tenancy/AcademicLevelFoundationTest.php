<?php

namespace Tests\Feature\Tenancy;

use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicLevelFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_level_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create([
                'name' => 'Beginner A1',
                'code' => 'A1',
                'sequence_number' => 1,
                'description' =>
                'Beginner academic level.',
            ]);

        $this->assertSame(
            $center->id,
            $level->center_id
        );

        $this->assertSame(
            $language->id,
            $level->language_id
        );

        $this->assertSame(
            'Beginner A1',
            $level->name
        );

        $this->assertSame(
            'A1',
            $level->code
        );

        $this->assertSame(
            1,
            $level->sequence_number
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $level->status
        );
    }

    public function test_new_academic_level_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::query()
            ->create([
                'center_id' => $center->id,
                'language_id' => $language->id,
                'name' => 'A1',
                'code' => 'A1',
                'sequence_number' => 1,
            ]);

        $level->refresh();

        $this->assertSame(
            AcademicRecordStatus::Active,
            $level->status
        );

        $this->assertTrue(
            $level->isActive()
        );

        $this->assertFalse(
            $level->isArchived()
        );

        $this->assertNull(
            $level->archived_at
        );
    }

    public function test_academic_level_casts_archived_lifecycle_state(): void
    {
        $level = AcademicLevel::factory()
            ->archived()
            ->create();

        $this->assertSame(
            AcademicRecordStatus::Archived,
            $level->status
        );

        $this->assertTrue(
            $level->isArchived()
        );

        $this->assertFalse(
            $level->isActive()
        );

        $this->assertNotNull(
            $level->archived_at
        );
    }

    public function test_academic_level_belongs_to_center_and_language(): void
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

        $this->assertTrue(
            $level->center->is(
                $center
            )
        );

        $this->assertTrue(
            $level->language->is(
                $language
            )
        );

        $this->assertTrue(
            $center->academicLevels
                ->contains($level)
        );

        $this->assertTrue(
            $language->academicLevels
                ->contains($level)
        );
    }

    public function test_database_rejects_language_from_another_center(): void
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

        $this->expectException(
            QueryException::class
        );

        AcademicLevel::query()
            ->create([
                'center_id' => $centerA->id,
                'language_id' => $languageB->id,
                'name' => 'Invalid Level',
                'code' => 'INVALID',
                'sequence_number' => 1,
            ]);
    }

    public function test_database_rejects_duplicate_sequence_inside_same_language(): void
    {
        $language = Language::factory()
            ->create();

        AcademicLevel::factory()
            ->forLanguage($language)
            ->create([
                'sequence_number' => 1,
            ]);

        $this->expectException(
            QueryException::class
        );

        AcademicLevel::factory()
            ->forLanguage($language)
            ->create([
                'sequence_number' => 1,
            ]);
    }

    public function test_same_sequence_is_allowed_for_different_languages(): void
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

        $levelA = AcademicLevel::factory()
            ->forLanguage($languageA)
            ->create([
                'sequence_number' => 1,
            ]);

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create([
                'sequence_number' => 1,
            ]);

        $this->assertSame(
            1,
            $levelA->sequence_number
        );

        $this->assertSame(
            1,
            $levelB->sequence_number
        );
    }

    public function test_academic_level_center_scope_excludes_other_centers(): void
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

        $centerB = Center::factory()
            ->active()
            ->create();

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = AcademicLevel::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $levelA->id,
            $ids
        );

        $this->assertNotContains(
            $levelB->id,
            $ids
        );
    }
}
