<?php

namespace Tests\Feature\Authorization;

use App\Models\AcademicLevel;
use App\Models\AuditRecord;
use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicCatalogManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_center_owner_can_create_language_and_scope_is_derived_from_tenant(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterContext(
            $centerA
        );

        $language = $this->service()
            ->createLanguage(
                $owner,
                [
                    /*
                     * None of these values may control ownership
                     * or lifecycle state.
                     */
                    'center_id' => $centerB->id,
                    'status' =>
                    AcademicRecordStatus::Archived,
                    'archived_at' => now(),

                    'name' => 'English',
                    'code' => 'EN',
                    'description' =>
                    'English Language',
                ]
            );

        $this->assertSame(
            $centerA->id,
            $language->center_id
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $language->status
        );

        $this->assertNull(
            $language->archived_at
        );

        $this->assertSame(
            'English',
            $language->name
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'language.created',
                $language
            )
        );
    }

    public function test_account_and_tenant_center_must_match(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $this->establishCenterContext(
            $centerB
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createLanguage(
                $ownerA,
                [
                    'name' => 'English',
                    'code' => 'EN',
                ]
            );
    }

    public function test_branch_manager_cannot_create_language_even_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->createLanguage(
                $manager,
                [
                    'name' => 'English',
                    'code' => 'EN',
                ]
            );
    }

    public function test_language_update_ignores_scope_and_lifecycle_attributes(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $language = Language::factory()
            ->for($centerA)
            ->active()
            ->create([
                'name' => 'Old Name',
                'code' => 'OLD',
            ]);

        $this->establishCenterContext(
            $centerA
        );

        $updated = $this->service()
            ->updateLanguage(
                $owner,
                $language,
                [
                    'center_id' => $centerB->id,
                    'status' =>
                    AcademicRecordStatus::Archived,
                    'archived_at' => now(),

                    'name' => 'English',
                    'code' => 'EN',
                ]
            );

        $this->assertSame(
            $centerA->id,
            $updated->center_id
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $updated->status
        );

        $this->assertNull(
            $updated->archived_at
        );

        $this->assertSame(
            'English',
            $updated->name
        );

        $this->assertSame(
            'EN',
            $updated->code
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'language.updated',
                $updated
            )
        );
    }

    public function test_no_change_language_update_creates_no_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create([
                'name' => 'English',
                'code' => 'EN',
                'description' => null,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->updateLanguage(
                $owner,
                $language,
                [
                    'name' => 'English',
                    'code' => 'EN',
                    'description' => null,
                ]
            );

        $this->assertSame(
            0,
            $this->auditCount(
                'language.updated',
                $language
            )
        );
    }

    public function test_language_cannot_be_archived_while_it_has_active_academic_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->archiveLanguage(
                    $owner,
                    $language
                );

            $this->fail(
                'Expected Language archival to be rejected.'
            );
        } catch (DomainException) {
            $language->refresh();

            $this->assertSame(
                AcademicRecordStatus::Active,
                $language->status
            );

            $this->assertNull(
                $language->archived_at
            );

            $this->assertSame(
                0,
                $this->auditCount(
                    'language.archived',
                    $language
                )
            );
        }
    }

    public function test_language_can_be_archived_when_all_levels_are_archived(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        AcademicLevel::factory()
            ->forLanguage($language)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $archived = $this->service()
            ->archiveLanguage(
                $owner,
                $language
            );

        $this->assertSame(
            AcademicRecordStatus::Archived,
            $archived->status
        );

        $this->assertNotNull(
            $archived->archived_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'language.archived',
                $archived
            )
        );
    }

    public function test_language_archive_and_restore_are_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->archiveLanguage(
                $owner,
                $language
            );

        $this->service()
            ->archiveLanguage(
                $owner,
                $language
            );

        $this->service()
            ->restoreLanguage(
                $owner,
                $language
            );

        $restored = $this->service()
            ->restoreLanguage(
                $owner,
                $language
            );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $restored->status
        );

        $this->assertNull(
            $restored->archived_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'language.archived',
                $language
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'language.restored',
                $language
            )
        );
    }

    public function test_center_owner_can_create_academic_level_under_active_language(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $language = Language::factory()
            ->for($centerA)
            ->active()
            ->create();

        $otherLanguage = Language::factory()
            ->for($centerB)
            ->active()
            ->create();

        $this->establishCenterContext(
            $centerA
        );

        $level = $this->service()
            ->createAcademicLevel(
                $owner,
                $language,
                [
                    /*
                     * Supplied scope/lifecycle values are ignored.
                     */
                    'center_id' => $centerB->id,
                    'language_id' =>
                    $otherLanguage->id,
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'name' => 'A1',
                    'code' => 'A1',
                    'sequence_number' => 1,
                    'description' =>
                    'Beginner Level',
                ]
            );

        $this->assertSame(
            $centerA->id,
            $level->center_id
        );

        $this->assertSame(
            $language->id,
            $level->language_id
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $level->status
        );

        $this->assertNull(
            $level->archived_at
        );

        $this->assertSame(
            1,
            $level->sequence_number
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'academic_level.created',
                $level
            )
        );
    }

    public function test_academic_level_cannot_be_created_under_archived_language(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createAcademicLevel(
                $owner,
                $language,
                [
                    'name' => 'A1',
                    'code' => 'A1',
                    'sequence_number' => 1,
                ]
            );
    }

    public function test_academic_level_sequence_must_be_unique_inside_language(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        AcademicLevel::factory()
            ->forLanguage($language)
            ->create([
                'sequence_number' => 1,
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createAcademicLevel(
                $owner,
                $language,
                [
                    'name' => 'Duplicate',
                    'code' => 'DUP',
                    'sequence_number' => 1,
                ]
            );
    }

    public function test_academic_level_update_cannot_move_scope_or_parent_language(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $languageA = Language::factory()
            ->for($center)
            ->create();

        $languageB = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($languageA)
            ->create([
                'name' => 'Old',
                'code' => 'OLD',
                'sequence_number' => 1,
            ]);

        $this->establishCenterContext(
            $center
        );

        $updated = $this->service()
            ->updateAcademicLevel(
                $owner,
                $level,
                [
                    'language_id' =>
                    $languageB->id,

                    'status' =>
                    AcademicRecordStatus::Archived,

                    'name' => 'A1',
                    'code' => 'A1',
                    'sequence_number' => 2,
                ]
            );

        $this->assertSame(
            $languageA->id,
            $updated->language_id
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $updated->status
        );

        $this->assertSame(
            2,
            $updated->sequence_number
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'academic_level.updated',
                $updated
            )
        );
    }

    public function test_academic_level_cannot_be_archived_while_it_has_active_course(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        Course::factory()
            ->forAcademicLevel($level)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->archiveAcademicLevel(
                    $owner,
                    $level
                );

            $this->fail(
                'Expected Academic Level archival to be rejected.'
            );
        } catch (DomainException) {
            $level->refresh();

            $this->assertSame(
                AcademicRecordStatus::Active,
                $level->status
            );

            $this->assertSame(
                0,
                $this->auditCount(
                    'academic_level.archived',
                    $level
                )
            );
        }
    }

    public function test_archived_academic_level_cannot_be_restored_while_language_is_archived(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->archived()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        try {
            $this->service()
                ->restoreAcademicLevel(
                    $owner,
                    $level
                );

            $this->fail(
                'Expected Academic Level restoration to be rejected.'
            );
        } catch (DomainException) {
            $level->refresh();

            $this->assertSame(
                AcademicRecordStatus::Archived,
                $level->status
            );

            $this->assertSame(
                0,
                $this->auditCount(
                    'academic_level.restored',
                    $level
                )
            );
        }
    }

    public function test_academic_level_archive_and_restore_are_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->archiveAcademicLevel(
                $owner,
                $level
            );

        $this->service()
            ->archiveAcademicLevel(
                $owner,
                $level
            );

        $this->service()
            ->restoreAcademicLevel(
                $owner,
                $level
            );

        $restored = $this->service()
            ->restoreAcademicLevel(
                $owner,
                $level
            );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $restored->status
        );

        $this->assertNull(
            $restored->archived_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'academic_level.archived',
                $level
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'academic_level.restored',
                $level
            )
        );
    }

    public function test_center_owner_can_create_course_under_active_academic_hierarchy(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $course = $this->service()
            ->createCourse(
                $owner,
                $language,
                $level,
                [
                    'center_id' => 999999,
                    'language_id' => 999999,
                    'academic_level_id' => 999999,
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'name' => 'English A1',
                    'code' => 'ENG-A1',
                    'description' =>
                    'Beginner English course.',
                    'duration_weeks' => 8,
                    'total_hours' => '40.50',
                    'default_fee' => '250.00',
                    'passing_grade' => '60.00',
                    'minimum_attendance' => '75.00',
                ]
            );

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
            AcademicRecordStatus::Active,
            $course->status
        );

        $this->assertNull(
            $course->archived_at
        );

        $this->assertSame(
            '40.50',
            $course->total_hours
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.created',
                $course
            )
        );
    }

    public function test_course_creation_rejects_mismatched_language_and_academic_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $languageA = Language::factory()
            ->for($center)
            ->create();

        $languageB = Language::factory()
            ->for($center)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createCourse(
                $owner,
                $languageA,
                $levelB,
                $this->validCourseAttributes()
            );
    }

    public function test_course_cannot_be_created_under_archived_language(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->archived()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createCourse(
                $owner,
                $language,
                $level,
                $this->validCourseAttributes()
            );
    }

    public function test_course_cannot_be_created_under_archived_academic_level(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->createCourse(
                $owner,
                $language,
                $level,
                $this->validCourseAttributes()
            );
    }

    public function test_course_creation_rejects_invalid_numeric_domain_values(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $this->establishCenterContext(
            $center
        );

        $invalidCases = [
            [
                'duration_weeks',
                0,
            ],
            [
                'total_hours',
                0,
            ],
            [
                'default_fee',
                -1,
            ],
            [
                'passing_grade',
                101,
            ],
            [
                'minimum_attendance',
                100.01,
            ],
            [
                'total_hours',
                '12.345',
            ],
        ];

        foreach (
            $invalidCases as [
                $field,
                $value,
            ]
        ) {
            try {
                $attributes =
                    $this->validCourseAttributes();

                $attributes[$field] = $value;

                $this->service()
                    ->createCourse(
                        $owner,
                        $language,
                        $level,
                        $attributes
                    );

                $this->fail(
                    "Expected {$field} validation to fail."
                );
            } catch (DomainException) {
                $this->assertDatabaseCount(
                    'courses',
                    0
                );
            }
        }
    }

    public function test_course_update_cannot_move_scope_or_academic_parents(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $languageA = Language::factory()
            ->for($center)
            ->create();

        $levelA = AcademicLevel::factory()
            ->forLanguage($languageA)
            ->create();

        $languageB = Language::factory()
            ->for($center)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($levelA)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $updated = $this->service()
            ->updateCourse(
                $owner,
                $course,
                [
                    'center_id' => 999999,
                    'language_id' =>
                    $languageB->id,
                    'academic_level_id' =>
                    $levelB->id,
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'name' => 'Updated Course',
                    'default_fee' => '300.00',
                ]
            );

        $this->assertSame(
            $center->id,
            $updated->center_id
        );

        $this->assertSame(
            $languageA->id,
            $updated->language_id
        );

        $this->assertSame(
            $levelA->id,
            $updated->academic_level_id
        );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $updated->status
        );

        $this->assertSame(
            'Updated Course',
            $updated->name
        );

        $this->assertSame(
            '300.00',
            $updated->default_fee
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.updated',
                $updated
            )
        );
    }

    public function test_no_change_course_update_creates_no_audit_record(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $course = Course::factory()
            ->for($center)
            ->create([
                'name' => 'English A1',
            ]);

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->updateCourse(
                $owner,
                $course,
                [
                    'name' => 'English A1',
                ]
            );

        $this->assertSame(
            0,
            $this->auditCount(
                'course.updated',
                $course
            )
        );
    }

    public function test_course_archive_and_restore_are_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->active()
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->active()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->archiveCourse(
                $owner,
                $course
            );

        $this->service()
            ->archiveCourse(
                $owner,
                $course
            );

        $this->service()
            ->restoreCourse(
                $owner,
                $course
            );

        $restored = $this->service()
            ->restoreCourse(
                $owner,
                $course
            );

        $this->assertSame(
            AcademicRecordStatus::Active,
            $restored->status
        );

        $this->assertNull(
            $restored->archived_at
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.archived',
                $course
            )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.restored',
                $course
            )
        );
    }

    public function test_course_cannot_be_restored_while_parent_language_is_archived(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->archived()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->archived()
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->restoreCourse(
                $owner,
                $course
            );
    }

    public function test_course_cannot_be_restored_while_academic_level_is_archived(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->active()
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->archived()
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->archived()
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->restoreCourse(
                $owner,
                $course
            );
    }

    public function test_center_owner_can_add_course_prerequisite(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

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

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $prerequisite,
                'completion'
            );

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'center_id' => $center->id,
                'course_id' => $course->id,
                'prerequisite_course_id' =>
                $prerequisite->id,
                'requirement_type' =>
                'completion',
            ]
        );

        $this->assertTrue(
            $course->fresh()
                ->prerequisites
                ->contains(
                    $prerequisite
                )
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.prerequisite_added',
                $course
            )
        );
    }

    public function test_course_cannot_be_its_own_prerequisite(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $course = Course::factory()
            ->for($center)
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $course
            );
    }

    public function test_cross_center_course_cannot_be_added_as_prerequisite(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $courseA = Course::factory()
            ->for($centerA)
            ->create();

        $courseB = Course::factory()
            ->for($centerB)
            ->create();

        $this->establishCenterContext(
            $centerA
        );

        $this->expectException(
            AuthorizationException::class
        );

        $this->service()
            ->addPrerequisite(
                $ownerA,
                $courseA,
                $courseB
            );
    }

    public function test_duplicate_course_prerequisite_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

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

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $prerequisite
            );

        $this->expectException(
            DomainException::class
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $prerequisite
            );
    }

    public function test_direct_circular_prerequisite_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $courseA = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->establishCenterContext(
            $center
        );

        /*
     * A requires B.
     */
        $this->service()
            ->addPrerequisite(
                $owner,
                $courseA,
                $courseB
            );

        try {
            /*
         * B requiring A would produce A -> B -> A.
         */
            $this->service()
                ->addPrerequisite(
                    $owner,
                    $courseB,
                    $courseA
                );

            $this->fail(
                'Expected circular prerequisite validation to fail.'
            );
        } catch (DomainException) {
            $this->assertDatabaseMissing(
                'course_prerequisites',
                [
                    'course_id' =>
                    $courseB->id,

                    'prerequisite_course_id' =>
                    $courseA->id,
                ]
            );
        }
    }

    public function test_indirect_circular_prerequisite_is_rejected(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $courseA = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $courseC = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->establishCenterContext(
            $center
        );

        /*
     * A -> B -> C
     */
        $this->service()
            ->addPrerequisite(
                $owner,
                $courseA,
                $courseB
            );

        $this->service()
            ->addPrerequisite(
                $owner,
                $courseB,
                $courseC
            );

        try {
            /*
         * C -> A would close the cycle.
         */
            $this->service()
                ->addPrerequisite(
                    $owner,
                    $courseC,
                    $courseA
                );

            $this->fail(
                'Expected indirect circular prerequisite validation to fail.'
            );
        } catch (DomainException) {
            $this->assertDatabaseMissing(
                'course_prerequisites',
                [
                    'course_id' =>
                    $courseC->id,

                    'prerequisite_course_id' =>
                    $courseA->id,
                ]
            );
        }
    }

    public function test_course_prerequisite_can_be_removed_and_removal_is_idempotent(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

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

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $prerequisite
            );

        $this->service()
            ->removePrerequisite(
                $owner,
                $course,
                $prerequisite
            );

        /*
     * Second removal is a no-op.
     */
        $this->service()
            ->removePrerequisite(
                $owner,
                $course,
                $prerequisite
            );

        $this->assertDatabaseMissing(
            'course_prerequisites',
            [
                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $prerequisite->id,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.prerequisite_removed',
                $course
            )
        );
    }

    public function test_complete_course_prerequisite_set_can_be_replaced(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $oldPrerequisite = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $newPrerequisiteA = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $newPrerequisiteB = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->establishCenterContext(
            $center
        );

        $this->service()
            ->addPrerequisite(
                $owner,
                $course,
                $oldPrerequisite
            );

        $this->service()
            ->replacePrerequisites(
                $owner,
                $course,
                [
                    [
                        'course_id' =>
                        $newPrerequisiteA->id,

                        'requirement_type' =>
                        'completion',
                    ],
                    [
                        'course_id' =>
                        $newPrerequisiteB->id,

                        'requirement_type' =>
                        null,
                    ],
                ]
            );

        $this->assertDatabaseMissing(
            'course_prerequisites',
            [
                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $oldPrerequisite->id,
            ]
        );

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $newPrerequisiteA->id,

                'requirement_type' =>
                'completion',
            ]
        );

        $this->assertDatabaseHas(
            'course_prerequisites',
            [
                'course_id' =>
                $course->id,

                'prerequisite_course_id' =>
                $newPrerequisiteB->id,

                'requirement_type' =>
                null,
            ]
        );

        $this->assertSame(
            1,
            $this->auditCount(
                'course.prerequisites_replaced',
                $course
            )
        );
    }

    public function test_replacing_prerequisites_rejects_cycle_and_preserves_existing_state(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $courseA = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $courseC = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->establishCenterContext(
            $center
        );

        /*
     * A -> B -> C
     */
        $this->service()
            ->addPrerequisite(
                $owner,
                $courseA,
                $courseB
            );

        $this->service()
            ->addPrerequisite(
                $owner,
                $courseB,
                $courseC
            );

        try {
            /*
         * Replacing C prerequisites with A would create a cycle.
         */
            $this->service()
                ->replacePrerequisites(
                    $owner,
                    $courseC,
                    [
                        [
                            'course_id' =>
                            $courseA->id,

                            'requirement_type' =>
                            null,
                        ],
                    ]
                );

            $this->fail(
                'Expected prerequisite replacement cycle validation to fail.'
            );
        } catch (DomainException) {
            $this->assertDatabaseMissing(
                'course_prerequisites',
                [
                    'course_id' =>
                    $courseC->id,

                    'prerequisite_course_id' =>
                    $courseA->id,
                ]
            );

            $this->assertSame(
                0,
                $this->auditCount(
                    'course.prerequisites_replaced',
                    $courseC
                )
            );
        }
    }

    private function service(): AcademicCatalogManagementService
    {
        return app(
            AcademicCatalogManagementService::class
        );
    }

    private function establishCenterContext(
        Center $center
    ): void {
        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role($role)->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' =>
                $this->role($role)->id,
                'status' =>
                AccountStatus::Active,
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

    /**
     * @return array<string, mixed>
     */
    private function validCourseAttributes(): array
    {
        return [
            'name' => 'English A1',
            'code' => 'ENG-A1',
            'description' =>
            'Beginner English course.',
            'duration_weeks' => 8,
            'total_hours' => '40.00',
            'default_fee' => '250.00',
            'passing_grade' => '60.00',
            'minimum_attendance' => '75.00',
        ];
    }

    private function auditCount(
        string $actionType,
        Language|AcademicLevel|Course $subject
    ): int {
        return AuditRecord::query()
            ->where(
                'action_type',
                $actionType
            )
            ->where(
                'subject_type',
                $subject->getTable()
            )
            ->where(
                'subject_id',
                $subject->id
            )
            ->count();
    }
}
