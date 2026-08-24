<?php

namespace App\Services\Academic;

use App\Models\AcademicLevel;
use App\Models\Course;
use App\Models\Language;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AcademicCatalogManagementService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit
    ) {}

    public function createLanguage(
        User $actor,
        array $attributes
    ): Language {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $attributes,
                $centerId
            ): Language {
                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        Language::class
                    );

                /*
                 * Center ownership and lifecycle state are never
                 * trusted from request input.
                 *
                 * New academic records always begin Active.
                 */
                $data = [
                    'center_id' => $centerId,

                    'name' =>
                    $this->requiredString(
                        $attributes,
                        'name',
                        'Language name',
                        255
                    ),

                    'code' =>
                    $this->requiredString(
                        $attributes,
                        'code',
                        'Language code',
                        50
                    ),

                    'description' =>
                    $this->nullableString(
                        $attributes,
                        'description',
                        'Language description',
                        255
                    ),

                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ];

                $language = Language::query()
                    ->create(
                        $data
                    );

                $language->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'language.created',
                    subject: $language,
                    afterValues: $this->languageAuditValues(
                        $language
                    )
                );

                return $language;
            },
            3
        );
    }

    public function updateLanguage(
        User $actor,
        Language $language,
        array $attributes
    ): Language {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $language,
                $attributes,
                $centerId
            ): Language {
                $language =
                    $this->lockPersistedLanguage(
                        $language,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $language
                    );

                $beforeValues =
                    $this->languageAuditValues(
                        $language
                    );

                $data = [];

                if (
                    array_key_exists(
                        'name',
                        $attributes
                    )
                ) {
                    $data['name'] =
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Language name',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'code',
                        $attributes
                    )
                ) {
                    $data['code'] =
                        $this->requiredString(
                            $attributes,
                            'code',
                            'Language code',
                            50
                        );
                }

                if (
                    array_key_exists(
                        'description',
                        $attributes
                    )
                ) {
                    $data['description'] =
                        $this->nullableString(
                            $attributes,
                            'description',
                            'Language description',
                            255
                        );
                }

                /*
                 * center_id, status, and archived_at cannot be
                 * changed through the general update operation.
                 */
                $language->fill(
                    $data
                );

                if (! $language->isDirty()) {
                    return $language;
                }

                $language->save();
                $language->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'language.updated',
                    subject: $language,
                    beforeValues: $beforeValues,
                    afterValues: $this->languageAuditValues(
                        $language
                    )
                );

                return $language;
            },
            3
        );
    }

    public function archiveLanguage(
        User $actor,
        Language $language
    ): Language {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $language,
                $centerId
            ): Language {
                $language =
                    $this->lockPersistedLanguage(
                        $language,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'archive',
                        $language
                    );

                /*
                 * Repeating the operation is idempotent and does
                 * not create a duplicate Audit Record.
                 */
                if (
                    $language->status
                    === AcademicRecordStatus::Archived
                ) {
                    return $language;
                }

                /*
                 * Data Retention and Lifecycle Policy:
                 *
                 * A Language cannot be archived while any
                 * Academic Level under it remains Active.
                 *
                 * createAcademicLevel() locks this same Language
                 * row, preventing creation from racing with this
                 * lifecycle transition.
                 */
                $hasActiveLevels =
                    AcademicLevel::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'language_id',
                        $language->id
                    )
                    ->where(
                        'status',
                        AcademicRecordStatus::Active->value
                    )
                    ->exists();

                if ($hasActiveLevels) {
                    throw new DomainException(
                        'A Language cannot be archived while it has active Academic Levels.'
                    );
                }

                $beforeValues =
                    $this->languageAuditValues(
                        $language
                    );

                $language->forceFill([
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'archived_at' => now(),
                ])->save();

                $language->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'language.archived',
                    subject: $language,
                    beforeValues: $beforeValues,
                    afterValues: $this->languageAuditValues(
                        $language
                    )
                );

                return $language;
            },
            3
        );
    }

    public function restoreLanguage(
        User $actor,
        Language $language
    ): Language {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $language,
                $centerId
            ): Language {
                $language =
                    $this->lockPersistedLanguage(
                        $language,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'restore',
                        $language
                    );

                if (
                    $language->status
                    === AcademicRecordStatus::Active
                ) {
                    return $language;
                }

                $beforeValues =
                    $this->languageAuditValues(
                        $language
                    );

                $language->forceFill([
                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ])->save();

                $language->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'language.restored',
                    subject: $language,
                    beforeValues: $beforeValues,
                    afterValues: $this->languageAuditValues(
                        $language
                    )
                );

                return $language;
            },
            3
        );
    }

    public function createAcademicLevel(
        User $actor,
        Language $language,
        array $attributes
    ): AcademicLevel {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $language,
                $attributes,
                $centerId
            ): AcademicLevel {
                /*
                 * Lock the persisted parent.
                 *
                 * This prevents creation from racing with Language
                 * archival and prevents stale model state from being
                 * trusted.
                 */
                $language =
                    $this->lockPersistedLanguage(
                        $language,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        AcademicLevel::class
                    );

                if (
                    $language->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'An Academic Level cannot be created under an archived Language.'
                    );
                }

                $sequenceNumber =
                    $this->requiredPositiveInteger(
                        $attributes,
                        'sequence_number',
                        'Academic Level sequence number'
                    );

                $this->ensureUniqueLevelSequence(
                    centerId: $centerId,
                    languageId: $language->id,
                    sequenceNumber: $sequenceNumber
                );

                /*
                 * center_id and language_id are derived from the
                 * authorized persisted parent.
                 */
                $data = [
                    'center_id' => $centerId,

                    'language_id' =>
                    $language->id,

                    'name' =>
                    $this->requiredString(
                        $attributes,
                        'name',
                        'Academic Level name',
                        255
                    ),

                    'code' =>
                    $this->requiredString(
                        $attributes,
                        'code',
                        'Academic Level code',
                        50
                    ),

                    'sequence_number' =>
                    $sequenceNumber,

                    'description' =>
                    $this->nullableString(
                        $attributes,
                        'description',
                        'Academic Level description',
                        255
                    ),

                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ];

                $academicLevel =
                    AcademicLevel::query()
                    ->create(
                        $data
                    );

                $academicLevel->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'academic_level.created',
                    subject: $academicLevel,
                    afterValues: $this->academicLevelAuditValues(
                        $academicLevel
                    )
                );

                return $academicLevel;
            },
            3
        );
    }

    public function updateAcademicLevel(
        User $actor,
        AcademicLevel $academicLevel,
        array $attributes
    ): AcademicLevel {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $academicLevel,
                $attributes,
                $centerId
            ): AcademicLevel {
                $academicLevel =
                    $this->lockPersistedAcademicLevel(
                        $academicLevel,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $academicLevel
                    );

                /*
                 * Lock the persisted parent as well.
                 *
                 * General update does not move a Level to another
                 * Language, but sequence validation belongs to the
                 * current parent structure.
                 */
                $language =
                    $this->lockPersistedLanguageById(
                        $academicLevel->language_id,
                        $centerId
                    );

                $beforeValues =
                    $this->academicLevelAuditValues(
                        $academicLevel
                    );

                $data = [];

                if (
                    array_key_exists(
                        'name',
                        $attributes
                    )
                ) {
                    $data['name'] =
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Academic Level name',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'code',
                        $attributes
                    )
                ) {
                    $data['code'] =
                        $this->requiredString(
                            $attributes,
                            'code',
                            'Academic Level code',
                            50
                        );
                }

                if (
                    array_key_exists(
                        'description',
                        $attributes
                    )
                ) {
                    $data['description'] =
                        $this->nullableString(
                            $attributes,
                            'description',
                            'Academic Level description',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'sequence_number',
                        $attributes
                    )
                ) {
                    $sequenceNumber =
                        $this->requiredPositiveInteger(
                            $attributes,
                            'sequence_number',
                            'Academic Level sequence number'
                        );

                    $this->ensureUniqueLevelSequence(
                        centerId: $centerId,
                        languageId: $language->id,
                        sequenceNumber: $sequenceNumber,
                        ignoreAcademicLevelId: $academicLevel->id
                    );

                    $data['sequence_number'] =
                        $sequenceNumber;
                }

                /*
                 * center_id, language_id, status, and archived_at
                 * are intentionally excluded.
                 */
                $academicLevel->fill(
                    $data
                );

                if (! $academicLevel->isDirty()) {
                    return $academicLevel;
                }

                $academicLevel->save();
                $academicLevel->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'academic_level.updated',
                    subject: $academicLevel,
                    beforeValues: $beforeValues,
                    afterValues: $this->academicLevelAuditValues(
                        $academicLevel
                    )
                );

                return $academicLevel;
            },
            3
        );
    }

    public function archiveAcademicLevel(
        User $actor,
        AcademicLevel $academicLevel
    ): AcademicLevel {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $academicLevel,
                $centerId
            ): AcademicLevel {
                $academicLevel =
                    $this->lockPersistedAcademicLevel(
                        $academicLevel,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'archive',
                        $academicLevel
                    );

                if (
                    $academicLevel->status
                    === AcademicRecordStatus::Archived
                ) {
                    return $academicLevel;
                }

                /*
                 * Data Retention and Lifecycle Policy:
                 *
                 * An Academic Level cannot be archived while
                 * it still owns Active Courses.
                 */
                $hasActiveCourses =
                    Course::withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'academic_level_id',
                        $academicLevel->id
                    )
                    ->where(
                        'status',
                        AcademicRecordStatus::Active->value
                    )
                    ->exists();

                if ($hasActiveCourses) {
                    throw new DomainException(
                        'An Academic Level cannot be archived while it has active Courses.'
                    );
                }

                $beforeValues =
                    $this->academicLevelAuditValues(
                        $academicLevel
                    );

                $academicLevel->forceFill([
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'archived_at' => now(),
                ])->save();

                $academicLevel->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'academic_level.archived',
                    subject: $academicLevel,
                    beforeValues: $beforeValues,
                    afterValues: $this->academicLevelAuditValues(
                        $academicLevel
                    )
                );

                return $academicLevel;
            },
            3
        );
    }

    public function restoreAcademicLevel(
        User $actor,
        AcademicLevel $academicLevel
    ): AcademicLevel {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $academicLevel,
                $centerId
            ): AcademicLevel {
                $academicLevel =
                    $this->lockPersistedAcademicLevel(
                        $academicLevel,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'restore',
                        $academicLevel
                    );

                if (
                    $academicLevel->status
                    === AcademicRecordStatus::Active
                ) {
                    return $academicLevel;
                }

                /*
                 * A child may return to Active only when its
                 * required parent is Active.
                 */
                $language =
                    $this->lockPersistedLanguageById(
                        $academicLevel->language_id,
                        $centerId
                    );

                if (
                    $language->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'An Academic Level cannot be restored while its Language is archived.'
                    );
                }

                $beforeValues =
                    $this->academicLevelAuditValues(
                        $academicLevel
                    );

                $academicLevel->forceFill([
                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ])->save();

                $academicLevel->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'academic_level.restored',
                    subject: $academicLevel,
                    beforeValues: $beforeValues,
                    afterValues: $this->academicLevelAuditValues(
                        $academicLevel
                    )
                );

                return $academicLevel;
            },
            3
        );
    }

    public function createCourse(
        User $actor,
        Language $language,
        AcademicLevel $academicLevel,
        array $attributes
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $language,
                $academicLevel,
                $attributes,
                $centerId
            ): Course {
                $language =
                    $this->lockPersistedLanguage(
                        $language,
                        $centerId
                    );

                $academicLevel =
                    $this->lockPersistedAcademicLevel(
                        $academicLevel,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'create',
                        Course::class
                    );

                if (
                    $academicLevel->language_id
                    !== $language->id
                ) {
                    throw new DomainException(
                        'The Academic Level must belong to the selected Language.'
                    );
                }

                if (
                    $language->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course cannot be created under an archived Language.'
                    );
                }

                if (
                    $academicLevel->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course cannot be created under an archived Academic Level.'
                    );
                }

                /*
             * Scope, parents, and lifecycle state are derived from
             * persisted authorized records and never trusted from
             * request input.
             */
                $data = [
                    'center_id' => $centerId,

                    'language_id' =>
                    $language->id,

                    'academic_level_id' =>
                    $academicLevel->id,

                    'name' =>
                    $this->requiredString(
                        $attributes,
                        'name',
                        'Course name',
                        255
                    ),

                    'code' =>
                    $this->requiredString(
                        $attributes,
                        'code',
                        'Course code',
                        50
                    ),

                    'description' =>
                    $this->nullableString(
                        $attributes,
                        'description',
                        'Course description',
                        65535
                    ),

                    'duration_weeks' =>
                    $this->requiredPositiveInteger(
                        $attributes,
                        'duration_weeks',
                        'Course duration in weeks'
                    ),

                    'total_hours' =>
                    $this->requiredDecimal(
                        $attributes,
                        'total_hours',
                        'Course total hours',
                        0.01,
                        999999.99
                    ),

                    'default_fee' =>
                    $this->requiredDecimal(
                        $attributes,
                        'default_fee',
                        'Course default fee',
                        0.00,
                        9999999999.99
                    ),

                    'passing_grade' =>
                    $this->requiredDecimal(
                        $attributes,
                        'passing_grade',
                        'Course passing grade',
                        0.00,
                        100.00
                    ),

                    'minimum_attendance' =>
                    $this->requiredDecimal(
                        $attributes,
                        'minimum_attendance',
                        'Course minimum attendance',
                        0.00,
                        100.00
                    ),

                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ];

                $course = Course::query()
                    ->create(
                        $data
                    );

                $course->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.created',
                    subject: $course,
                    afterValues: $this->courseAuditValues(
                        $course
                    )
                );

                return $course;
            },
            3
        );
    }

    public function updateCourse(
        User $actor,
        Course $course,
        array $attributes
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $attributes,
                $centerId
            ): Course {
                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $course
                    );

                /*
             * Re-read the parents so the operation never depends
             * on stale relationship state.
             */
                $language =
                    $this->lockPersistedLanguageById(
                        $course->language_id,
                        $centerId
                    );

                $academicLevel =
                    $this->lockPersistedAcademicLevelById(
                        $course->academic_level_id,
                        $centerId
                    );

                if (
                    $academicLevel->language_id
                    !== $language->id
                ) {
                    throw new DomainException(
                        'The Course academic hierarchy is inconsistent.'
                    );
                }

                $beforeValues =
                    $this->courseAuditValues(
                        $course
                    );

                $data = [];

                if (
                    array_key_exists(
                        'name',
                        $attributes
                    )
                ) {
                    $data['name'] =
                        $this->requiredString(
                            $attributes,
                            'name',
                            'Course name',
                            255
                        );
                }

                if (
                    array_key_exists(
                        'code',
                        $attributes
                    )
                ) {
                    $data['code'] =
                        $this->requiredString(
                            $attributes,
                            'code',
                            'Course code',
                            50
                        );
                }

                if (
                    array_key_exists(
                        'description',
                        $attributes
                    )
                ) {
                    $data['description'] =
                        $this->nullableString(
                            $attributes,
                            'description',
                            'Course description',
                            65535
                        );
                }

                if (
                    array_key_exists(
                        'duration_weeks',
                        $attributes
                    )
                ) {
                    $data['duration_weeks'] =
                        $this->requiredPositiveInteger(
                            $attributes,
                            'duration_weeks',
                            'Course duration in weeks'
                        );
                }

                if (
                    array_key_exists(
                        'total_hours',
                        $attributes
                    )
                ) {
                    $data['total_hours'] =
                        $this->requiredDecimal(
                            $attributes,
                            'total_hours',
                            'Course total hours',
                            0.01,
                            999999.99
                        );
                }

                if (
                    array_key_exists(
                        'default_fee',
                        $attributes
                    )
                ) {
                    $data['default_fee'] =
                        $this->requiredDecimal(
                            $attributes,
                            'default_fee',
                            'Course default fee',
                            0.00,
                            9999999999.99
                        );
                }

                if (
                    array_key_exists(
                        'passing_grade',
                        $attributes
                    )
                ) {
                    $data['passing_grade'] =
                        $this->requiredDecimal(
                            $attributes,
                            'passing_grade',
                            'Course passing grade',
                            0.00,
                            100.00
                        );
                }

                if (
                    array_key_exists(
                        'minimum_attendance',
                        $attributes
                    )
                ) {
                    $data['minimum_attendance'] =
                        $this->requiredDecimal(
                            $attributes,
                            'minimum_attendance',
                            'Course minimum attendance',
                            0.00,
                            100.00
                        );
                }

                /*
             * center_id, language_id, academic_level_id,
             * status, and archived_at cannot be changed through
             * the general update operation.
             */
                $course->fill(
                    $data
                );

                if (! $course->isDirty()) {
                    return $course;
                }

                $course->save();
                $course->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.updated',
                    subject: $course,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseAuditValues(
                        $course
                    )
                );

                return $course;
            },
            3
        );
    }

    public function archiveCourse(
        User $actor,
        Course $course
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $centerId
            ): Course {
                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'archive',
                        $course
                    );

                if (
                    $course->status
                    === AcademicRecordStatus::Archived
                ) {
                    return $course;
                }

                $beforeValues =
                    $this->courseAuditValues(
                        $course
                    );

                /*
             * Course history and prerequisite relationships remain
             * preserved. Archiving changes only lifecycle state.
             */
                $course->forceFill([
                    'status' =>
                    AcademicRecordStatus::Archived,

                    'archived_at' => now(),
                ])->save();

                $course->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.archived',
                    subject: $course,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseAuditValues(
                        $course
                    )
                );

                return $course;
            },
            3
        );
    }

    public function restoreCourse(
        User $actor,
        Course $course
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $centerId
            ): Course {
                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'restore',
                        $course
                    );

                if (
                    $course->status
                    === AcademicRecordStatus::Active
                ) {
                    return $course;
                }

                $language =
                    $this->lockPersistedLanguageById(
                        $course->language_id,
                        $centerId
                    );

                $academicLevel =
                    $this->lockPersistedAcademicLevelById(
                        $course->academic_level_id,
                        $centerId
                    );

                if (
                    $academicLevel->language_id
                    !== $language->id
                ) {
                    throw new DomainException(
                        'The Course academic hierarchy is inconsistent.'
                    );
                }

                if (
                    $language->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course cannot be restored while its Language is archived.'
                    );
                }

                if (
                    $academicLevel->status
                    !== AcademicRecordStatus::Active
                ) {
                    throw new DomainException(
                        'A Course cannot be restored while its Academic Level is archived.'
                    );
                }

                $beforeValues =
                    $this->courseAuditValues(
                        $course
                    );

                $course->forceFill([
                    'status' =>
                    AcademicRecordStatus::Active,

                    'archived_at' => null,
                ])->save();

                $course->refresh();

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.restored',
                    subject: $course,
                    beforeValues: $beforeValues,
                    afterValues: $this->courseAuditValues(
                        $course
                    )
                );

                return $course;
            },
            3
        );
    }

    public function addPrerequisite(
        User $actor,
        Course $course,
        Course $prerequisite,
        ?string $requirementType = null
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $prerequisite,
                $requirementType,
                $centerId
            ): Course {
                /*
             * Serialize prerequisite-graph changes inside the
             * current Center.
             *
             * This protects cycle validation from concurrent
             * graph mutations.
             */
                $this->lockPrerequisiteGraph(
                    $centerId
                );

                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                $prerequisite =
                    $this->lockPersistedCourse(
                        $prerequisite,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $course
                    );

                if (
                    $course->id
                    === $prerequisite->id
                ) {
                    throw new DomainException(
                        'A Course cannot be its own prerequisite.'
                    );
                }

                $normalizedRequirementType =
                    $this->normalizeRequirementType(
                        $requirementType
                    );

                $alreadyExists =
                    DB::table(
                        'course_prerequisites'
                    )
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->where(
                        'prerequisite_course_id',
                        $prerequisite->id
                    )
                    ->exists();

                if ($alreadyExists) {
                    throw new DomainException(
                        'The selected Course is already a prerequisite.'
                    );
                }

                $beforePrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                $edges =
                    $this->currentPrerequisiteEdges(
                        $centerId
                    );

                $edges[] = [
                    $course->id,
                    $prerequisite->id,
                ];

                $this->ensureAcyclicPrerequisiteGraph(
                    $edges
                );

                DB::table(
                    'course_prerequisites'
                )->insert([
                    'center_id' =>
                    $centerId,

                    'course_id' =>
                    $course->id,

                    'prerequisite_course_id' =>
                    $prerequisite->id,

                    'requirement_type' =>
                    $normalizedRequirementType,

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $afterPrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.prerequisite_added',
                    subject: $course,
                    beforeValues: [
                        'prerequisites' =>
                        $beforePrerequisites,
                    ],
                    afterValues: [
                        'prerequisites' =>
                        $afterPrerequisites,
                    ]
                );

                return $course->refresh();
            },
            3
        );
    }

    public function removePrerequisite(
        User $actor,
        Course $course,
        Course $prerequisite
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $prerequisite,
                $centerId
            ): Course {
                $this->lockPrerequisiteGraph(
                    $centerId
                );

                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                $prerequisite =
                    $this->lockPersistedCourse(
                        $prerequisite,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $course
                    );

                $existing =
                    DB::table(
                        'course_prerequisites'
                    )
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->where(
                        'prerequisite_course_id',
                        $prerequisite->id
                    )
                    ->exists();

                /*
             * Removing an already-absent prerequisite is
             * idempotent and produces no duplicate Audit Record.
             */
                if (! $existing) {
                    return $course;
                }

                $beforePrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                DB::table(
                    'course_prerequisites'
                )
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->where(
                        'prerequisite_course_id',
                        $prerequisite->id
                    )
                    ->delete();

                $afterPrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.prerequisite_removed',
                    subject: $course,
                    beforeValues: [
                        'prerequisites' =>
                        $beforePrerequisites,
                    ],
                    afterValues: [
                        'prerequisites' =>
                        $afterPrerequisites,
                    ]
                );

                return $course->refresh();
            },
            3
        );
    }

    /**
     * Replace the complete prerequisite set for one Course.
     *
     * Expected input:
     *
     * [
     *     [
     *         'course_id' => 12,
     *         'requirement_type' => 'completion',
     *     ],
     *     [
     *         'course_id' => 15,
     *         'requirement_type' => null,
     *     ],
     * ]
     */
    public function replacePrerequisites(
        User $actor,
        Course $course,
        array $prerequisites
    ): Course {
        $centerId = $this->authorizedCenterId(
            $actor
        );

        return DB::transaction(
            function () use (
                $actor,
                $course,
                $prerequisites,
                $centerId
            ): Course {
                $this->lockPrerequisiteGraph(
                    $centerId
                );

                $course =
                    $this->lockPersistedCourse(
                        $course,
                        $centerId
                    );

                Gate::forUser($actor)
                    ->authorize(
                        'update',
                        $course
                    );

                $normalized = [];
                $seenIds = [];

                foreach (
                    $prerequisites as $index => $item
                ) {
                    if (! is_array($item)) {
                        throw new DomainException(
                            "Prerequisite entry {$index} must be an array."
                        );
                    }

                    if (
                        ! array_key_exists(
                            'course_id',
                            $item
                        )
                    ) {
                        throw new DomainException(
                            "Prerequisite entry {$index} must contain a Course identifier."
                        );
                    }

                    $prerequisiteId =
                        $this->positiveIdentifier(
                            $item['course_id'],
                            'Prerequisite Course identifier'
                        );

                    if (
                        $prerequisiteId
                        === $course->id
                    ) {
                        throw new DomainException(
                            'A Course cannot be its own prerequisite.'
                        );
                    }

                    if (
                        isset(
                            $seenIds[$prerequisiteId]
                        )
                    ) {
                        throw new DomainException(
                            'The same prerequisite Course cannot be supplied more than once.'
                        );
                    }

                    /*
                 * Re-read every Course from the database and enforce
                 * same-Center ownership.
                 */
                    $this->lockPersistedCourseById(
                        $prerequisiteId,
                        $centerId
                    );

                    $requirementType =
                        $this->normalizeRequirementType(
                            $item['requirement_type']
                                ?? null
                        );

                    $seenIds[$prerequisiteId] =
                        true;

                    $normalized[] = [
                        'prerequisite_course_id' =>
                        $prerequisiteId,

                        'requirement_type' =>
                        $requirementType,
                    ];
                }

                usort(
                    $normalized,
                    fn(
                        array $left,
                        array $right
                    ): int =>
                    $left['prerequisite_course_id']
                        <=>
                        $right['prerequisite_course_id']
                );

                $beforePrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                /*
             * Exact same state is a no-op.
             */
                if (
                    $beforePrerequisites
                    === $normalized
                ) {
                    return $course;
                }

                /*
             * Build the proposed complete graph:
             * keep all edges except current Course outgoing edges,
             * then add the requested replacement set.
             */
                $edges = [];

                foreach (
                    $this->currentPrerequisiteEdges(
                        $centerId
                    ) as $edge
                ) {
                    if (
                        $edge[0]
                        === $course->id
                    ) {
                        continue;
                    }

                    $edges[] = $edge;
                }

                foreach (
                    $normalized as $item
                ) {
                    $edges[] = [
                        $course->id,
                        $item['prerequisite_course_id'],
                    ];
                }

                $this->ensureAcyclicPrerequisiteGraph(
                    $edges
                );

                DB::table(
                    'course_prerequisites'
                )
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'course_id',
                        $course->id
                    )
                    ->delete();

                if ($normalized !== []) {
                    $now = now();

                    $rows = array_map(
                        fn(array $item): array => [
                            'center_id' =>
                            $centerId,

                            'course_id' =>
                            $course->id,

                            'prerequisite_course_id' =>
                            $item['prerequisite_course_id'],

                            'requirement_type' =>
                            $item['requirement_type'],

                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $normalized
                    );

                    DB::table(
                        'course_prerequisites'
                    )->insert(
                        $rows
                    );
                }

                $afterPrerequisites =
                    $this->coursePrerequisiteAuditValues(
                        $centerId,
                        $course->id
                    );

                $this->audit->record(
                    actor: $actor,
                    actionType: 'course.prerequisites_replaced',
                    subject: $course,
                    beforeValues: [
                        'prerequisites' =>
                        $beforePrerequisites,
                    ],
                    afterValues: [
                        'prerequisites' =>
                        $afterPrerequisites,
                    ]
                );

                return $course->refresh();
            },
            3
        );
    }

    private function authorizedCenterId(
        User $actor
    ): int {
        $center = $this->tenant
            ->requireCenter();

        if (
            $actor->center_id
            !== $center->id
        ) {
            throw new AuthorizationException(
                'Authenticated account and tenant context do not match.'
            );
        }

        return $center->id;
    }

    private function lockPersistedLanguage(
        Language $language,
        int $centerId
    ): Language {
        return $this->lockPersistedLanguageById(
            (int) $language->getKey(),
            $centerId
        );
    }

    private function lockPersistedLanguageById(
        int $languageId,
        int $centerId
    ): Language {
        $language =
            Language::withoutGlobalScopes()
            ->whereKey(
                $languageId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $language->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Language is outside the authorized Center scope.'
            );
        }

        return $language;
    }

    private function lockPersistedAcademicLevel(
        AcademicLevel $academicLevel,
        int $centerId
    ): AcademicLevel {
        return $this->lockPersistedAcademicLevelById(
            (int) $academicLevel->getKey(),
            $centerId
        );
    }

    private function lockPersistedAcademicLevelById(
        int $academicLevelId,
        int $centerId
    ): AcademicLevel {
        $academicLevel =
            AcademicLevel::withoutGlobalScopes()
            ->whereKey(
                $academicLevelId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $academicLevel->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Academic Level is outside the authorized Center scope.'
            );
        }

        return $academicLevel;
    }

    private function lockPersistedCourse(
        Course $course,
        int $centerId
    ): Course {
        return $this->lockPersistedCourseById(
            (int) $course->getKey(),
            $centerId
        );
    }

    private function lockPersistedCourseById(
        int $courseId,
        int $centerId
    ): Course {
        $course =
            Course::withoutGlobalScopes()
            ->whereKey(
                $courseId
            )
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $course->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The Course is outside the authorized Center scope.'
            );
        }

        return $course;
    }

    private function ensureUniqueLevelSequence(
        int $centerId,
        int $languageId,
        int $sequenceNumber,
        ?int $ignoreAcademicLevelId = null
    ): void {
        $query =
            AcademicLevel::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'language_id',
                $languageId
            )
            ->where(
                'sequence_number',
                $sequenceNumber
            );

        if (
            $ignoreAcademicLevelId
            !== null
        ) {
            $query->whereKeyNot(
                $ignoreAcademicLevelId
            );
        }

        if ($query->exists()) {
            throw new DomainException(
                'Academic Level sequence numbers must be unique within the same Language.'
            );
        }
    }

    private function requiredString(
        array $attributes,
        string $key,
        string $label,
        int $maxLength
    ): string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
            || ! is_string(
                $attributes[$key]
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value = trim(
            $attributes[$key]
        );

        if ($value === '') {
            throw new DomainException(
                "{$label} is required."
            );
        }

        if (
            mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function nullableString(
        array $attributes,
        string $key,
        string $label,
        int $maxLength
    ): ?string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
            || $attributes[$key] === null
        ) {
            return null;
        }

        if (
            ! is_string(
                $attributes[$key]
            )
        ) {
            throw new DomainException(
                "{$label} must be a string or null."
            );
        }

        $value = trim(
            $attributes[$key]
        );

        if ($value === '') {
            return null;
        }

        if (
            mb_strlen($value)
            > $maxLength
        ) {
            throw new DomainException(
                "{$label} must not exceed {$maxLength} characters."
            );
        }

        return $value;
    }

    private function requiredPositiveInteger(
        array $attributes,
        string $key,
        string $label
    ): int {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value = $attributes[$key];

        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new DomainException(
            "{$label} must be a positive integer."
        );
    }

    private function requiredDecimal(
        array $attributes,
        string $key,
        string $label,
        float $minimum,
        float $maximum
    ): string {
        if (
            ! array_key_exists(
                $key,
                $attributes
            )
        ) {
            throw new DomainException(
                "{$label} is required."
            );
        }

        $value = $attributes[$key];

        if (
            ! is_int($value)
            && ! is_float($value)
            && ! is_string($value)
        ) {
            throw new DomainException(
                "{$label} must be a valid numeric value."
            );
        }

        $rawValue = trim(
            (string) $value
        );

        /*
     * Monetary and academic decimal fields currently persist with
     * exactly two decimal places. Reject excess precision instead
     * of silently relying on database rounding.
     */
        if (
            preg_match(
                '/\A\d+(?:\.\d{1,2})?\z/',
                $rawValue
            ) !== 1
        ) {
            throw new DomainException(
                "{$label} must be a decimal value with at most two decimal places."
            );
        }

        $numericValue = (float) $rawValue;

        if (
            $numericValue < $minimum
            || $numericValue > $maximum
        ) {
            throw new DomainException(
                "{$label} is outside the allowed range."
            );
        }

        return number_format(
            $numericValue,
            2,
            '.',
            ''
        );
    }

    private function lockPrerequisiteGraph(
        int $centerId
    ): void {
        /*
     * Lock all Course rows in deterministic order.
     *
     * Every prerequisite graph mutation follows this same locking
     * convention, serializing graph validation inside one Center.
     */
        Course::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
            ]);

        DB::table(
            'course_prerequisites'
        )
            ->where(
                'center_id',
                $centerId
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
            ]);
    }

    /**
     * @return array<int, array{0:int, 1:int}>
     */
    private function currentPrerequisiteEdges(
        int $centerId
    ): array {
        return DB::table(
            'course_prerequisites'
        )
            ->where(
                'center_id',
                $centerId
            )
            ->orderBy('id')
            ->get([
                'course_id',
                'prerequisite_course_id',
            ])
            ->map(
                fn(object $row): array => [
                    (int) $row->course_id,

                    (int) $row
                        ->prerequisite_course_id,
                ]
            )
            ->all();
    }

    /**
     * @param array<int, array{0:int, 1:int}> $edges
     */
    private function ensureAcyclicPrerequisiteGraph(
        array $edges
    ): void {
        $adjacency = [];

        foreach ($edges as $edge) {
            [
                $courseId,
                $prerequisiteId,
            ] = $edge;

            if (
                $courseId
                === $prerequisiteId
            ) {
                throw new DomainException(
                    'A Course cannot be its own prerequisite.'
                );
            }

            $adjacency[$courseId][] =
                $prerequisiteId;
        }

        /*
     * DFS states:
     * 1 = currently visiting
     * 2 = fully visited
     *
     * Reaching a currently-visiting node proves a cycle.
     */
        $states = [];

        $visit = function (
            int $courseId
        ) use (
            &$visit,
            &$states,
            $adjacency
        ): void {
            $state =
                $states[$courseId]
                ?? 0;

            if ($state === 1) {
                throw new DomainException(
                    'Course prerequisites cannot contain circular dependencies.'
                );
            }

            if ($state === 2) {
                return;
            }

            $states[$courseId] = 1;

            foreach (
                $adjacency[$courseId]
                    ?? []
                as $prerequisiteId
            ) {
                $visit(
                    $prerequisiteId
                );
            }

            $states[$courseId] = 2;
        };

        foreach (
            array_keys($adjacency)
            as $courseId
        ) {
            $visit(
                (int) $courseId
            );
        }
    }

    private function normalizeRequirementType(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new DomainException(
                'Prerequisite requirement type must be a string or null.'
            );
        }

        $value = trim(
            $value
        );

        if ($value === '') {
            return null;
        }

        if (
            mb_strlen($value)
            > 50
        ) {
            throw new DomainException(
                'Prerequisite requirement type must not exceed 50 characters.'
            );
        }

        /*
     * The approved data model contains requirement_type,
     * but currently defines no fixed enum values.
     */
        return $value;
    }

    private function positiveIdentifier(
        mixed $value,
        string $label
    ): int {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new DomainException(
            "{$label} must be a positive integer."
        );
    }

    /**
     * @return array<int, array{
     *     prerequisite_course_id:int,
     *     requirement_type:?string
     * }>
     */
    private function coursePrerequisiteAuditValues(
        int $centerId,
        int $courseId
    ): array {
        return DB::table(
            'course_prerequisites'
        )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'course_id',
                $courseId
            )
            ->orderBy(
                'prerequisite_course_id'
            )
            ->get([
                'prerequisite_course_id',
                'requirement_type',
            ])
            ->map(
                fn(object $row): array => [
                    'prerequisite_course_id' =>
                    (int) $row
                        ->prerequisite_course_id,

                    'requirement_type' =>
                    $row->requirement_type,
                ]
            )
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function languageAuditValues(
        Language $language
    ): array {
        return [
            'center_id' =>
            $language->center_id,

            'name' =>
            $language->name,

            'code' =>
            $language->code,

            'description' =>
            $language->description,

            'status' =>
            $language->status,

            'archived_at' =>
            $language->archived_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function academicLevelAuditValues(
        AcademicLevel $academicLevel
    ): array {
        return [
            'center_id' =>
            $academicLevel->center_id,

            'language_id' =>
            $academicLevel->language_id,

            'name' =>
            $academicLevel->name,

            'code' =>
            $academicLevel->code,

            'sequence_number' =>
            $academicLevel
                ->sequence_number,

            'description' =>
            $academicLevel
                ->description,

            'status' =>
            $academicLevel->status,

            'archived_at' =>
            $academicLevel
                ->archived_at,
        ];
    }
    /**
     * @return array<string, mixed>
     */
    private function courseAuditValues(
        Course $course
    ): array {
        return [
            'center_id' =>
            $course->center_id,

            'language_id' =>
            $course->language_id,

            'academic_level_id' =>
            $course->academic_level_id,

            'name' =>
            $course->name,

            'code' =>
            $course->code,

            'description' =>
            $course->description,

            'duration_weeks' =>
            $course->duration_weeks,

            'total_hours' =>
            $course->total_hours,

            'default_fee' =>
            $course->default_fee,

            'passing_grade' =>
            $course->passing_grade,

            'minimum_attendance' =>
            $course->minimum_attendance,

            'status' =>
            $course->status,

            'archived_at' =>
            $course->archived_at,
        ];
    }
}
