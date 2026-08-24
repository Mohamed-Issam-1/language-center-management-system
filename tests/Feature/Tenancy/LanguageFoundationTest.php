<?php

namespace Tests\Feature\Tenancy;

use App\Models\Center;
use App\Models\Language;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_language_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create([
                'name' => 'English',
                'code' => 'EN',
                'description' =>
                'English language catalog.',
            ]);

        $this->assertSame(
            $center->id,
            $language->center_id
        );

        $this->assertSame(
            'English',
            $language->name
        );

        $this->assertSame(
            'EN',
            $language->code
        );

        $this->assertSame(
            'English language catalog.',
            $language->description
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $language->status
        );

        $this->assertNull(
            $language->archived_at
        );
    }

    public function test_new_language_defaults_to_active_at_database_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::query()
            ->create([
                'center_id' => $center->id,
                'name' => 'English',
                'code' => 'EN',
            ]);

        $language->refresh();

        $this->assertSame(
            AcademicRecordStatus::Active,
            $language->status
        );

        $this->assertTrue(
            $language->isActive()
        );

        $this->assertFalse(
            $language->isArchived()
        );

        $this->assertNull(
            $language->archived_at
        );
    }

    public function test_language_casts_active_and_archived_lifecycle_state(): void
    {
        $activeLanguage = Language::factory()
            ->active()
            ->create();

        $archivedLanguage = Language::factory()
            ->archived()
            ->create();

        $this->assertSame(
            AcademicRecordStatus::Active,
            $activeLanguage->status
        );

        $this->assertTrue(
            $activeLanguage->isActive()
        );

        $this->assertFalse(
            $activeLanguage->isArchived()
        );

        $this->assertNull(
            $activeLanguage->archived_at
        );

        $this->assertSame(
            AcademicRecordStatus::Archived,
            $archivedLanguage->status
        );

        $this->assertFalse(
            $archivedLanguage->isActive()
        );

        $this->assertTrue(
            $archivedLanguage->isArchived()
        );

        $this->assertNotNull(
            $archivedLanguage->archived_at
        );
    }

    public function test_language_belongs_to_center_and_center_exposes_languages(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $language = Language::factory()
            ->for($center)
            ->create();

        $this->assertTrue(
            $language->center->is(
                $center
            )
        );

        $this->assertTrue(
            $center->languages
                ->contains($language)
        );
    }

    public function test_same_language_code_is_allowed_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        Language::factory()
            ->for($centerA)
            ->create([
                'code' => 'EN',
            ]);

        $languageB = Language::factory()
            ->for($centerB)
            ->create([
                'code' => 'EN',
            ]);

        $this->assertDatabaseHas(
            'languages',
            [
                'id' => $languageB->id,
                'center_id' => $centerB->id,
                'code' => 'EN',
            ]
        );
    }

    public function test_language_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $languageA = Language::factory()
            ->for($centerA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = Language::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $languageA->id,
            $ids
        );

        $this->assertNotContains(
            $languageB->id,
            $ids
        );
    }
}
