<?php

namespace App\Filament\Resources\Courses;

use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ViewCourse;
use App\Models\AcademicLevel;
use App\Models\Course;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Actions\ActionGroup;

class CourseResource extends Resource
{
    protected static ?string $model =
    Course::class;

    protected static ?string $navigationLabel =
    'Courses';

    protected static ?string $modelLabel =
    'Course';

    protected static ?string $pluralModelLabel =
    'Courses';

    protected static string | \UnitEnum | null $navigationGroup =
    'Academics';

    protected static ?int $navigationSort =
    30;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Course Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Course'),

                        TextEntry::make('code')
                            ->label('Code'),

                        TextEntry::make(
                            'language.name'
                        )
                            ->label('Language'),

                        TextEntry::make(
                            'academicLevel.name'
                        )
                            ->label(
                                'Academic Level'
                            ),

                        TextEntry::make(
                            'duration_weeks'
                        )
                            ->label(
                                'Duration'
                            )
                            ->suffix(' weeks'),

                        TextEntry::make(
                            'total_hours'
                        )
                            ->label(
                                'Total Hours'
                            ),

                        TextEntry::make(
                            'default_fee'
                        )
                            ->label(
                                'Default Fee'
                            ),

                        TextEntry::make(
                            'passing_grade'
                        )
                            ->label(
                                'Passing Grade'
                            )
                            ->suffix('%'),

                        TextEntry::make(
                            'minimum_attendance'
                        )
                            ->label(
                                'Minimum Attendance'
                            )
                            ->suffix('%'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusColor(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'description'
                        )
                            ->label(
                                'Description'
                            )
                            ->placeholder(
                                'No description'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(
                    'Dependencies & Usage'
                )
                    ->schema([
                        TextEntry::make(
                            'prerequisites_count'
                        )
                            ->label(
                                'Prerequisites'
                            ),

                        TextEntry::make(
                            'required_by_courses_count'
                        )
                            ->label(
                                'Required By Courses'
                            ),

                        TextEntry::make(
                            'course_classes_count'
                        )
                            ->label(
                                'Course Classes'
                            ),

                        TextEntry::make(
                            'archived_at'
                        )
                            ->label(
                                'Archived At'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Not archived'
                            ),
                    ])
                    ->columns(4),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Course')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'language.name'
                )
                    ->label('Language')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'academicLevel.name'
                )
                    ->label('Level')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'duration_weeks'
                )
                    ->label('Weeks')
                    ->sortable(),

                TextColumn::make(
                    'total_hours'
                )
                    ->label('Hours')
                    ->sortable(),

                TextColumn::make(
                    'default_fee'
                )
                    ->label('Fee')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::statusLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::statusColor(
                            $state
                        )
                    ),

                TextColumn::make(
                    'passing_grade'
                )
                    ->label(
                        'Passing'
                    )
                    ->suffix('%')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'minimum_attendance'
                )
                    ->label(
                        'Attendance'
                    )
                    ->suffix('%')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'course_classes_count'
                )
                    ->label('Classes')
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'language_id'
                )
                    ->label('Language')
                    ->options(
                        fn(): array =>
                        static::languageOptions()
                    ),

                SelectFilter::make(
                    'academic_level_id'
                )
                    ->label(
                        'Academic Level'
                    )
                    ->options(
                        fn(): array =>
                        static::academicLevelOptions(
                            false
                        )
                    ),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        AcademicRecordStatus
                        ::Active
                            ->value =>
                        'Active',

                        AcademicRecordStatus
                        ::Archived
                            ->value =>
                        'Archived',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                Action::make(
                    'updateCourse'
                )
                    ->label('Update')
                    ->color('gray')
                    ->visible(
                        fn(
                            Course $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Course'
                    )
                    ->modalDescription(
                        'Update Course information and policy values. Language, Academic Level, Center, prerequisites, and lifecycle are managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->fillForm(
                        fn(
                            Course $record
                        ): array => [
                            'name' =>
                            $record->name,

                            'code' =>
                            $record->code,

                            'description' =>
                            $record
                                ->description,

                            'duration_weeks' =>
                            $record
                                ->duration_weeks,

                            'total_hours' =>
                            $record
                                ->total_hours,

                            'default_fee' =>
                            $record
                                ->default_fee,

                            'passing_grade' =>
                            $record
                                ->passing_grade,

                            'minimum_attendance' =>
                            $record
                                ->minimum_attendance,
                        ]
                    )
                    ->schema(
                        static::courseFields()
                    )
                    ->action(
                        function (
                            Course $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Course could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    AcademicCatalogManagementService::class
                                )->updateCourse(
                                    $actor,
                                    $record,
                                    $data
                                );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::failure(
                                    'Course could not be updated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Course could not be updated.',
                                    'An unexpected error occurred while updating the Course.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Course updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'managePrerequisites'
                )
                    ->label(
                        'Manage Prerequisites'
                    )
                    ->color('primary')
                    ->visible(
                        fn(
                            Course $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Manage Course Prerequisites'
                    )
                    ->modalDescription(
                        'Define the complete prerequisite set for this Course. Requirement Type is optional free text; LCMS currently does not define fixed requirement-type values.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Prerequisites'
                    )
                    ->fillForm(
                        function (
                            Course $record
                        ): array {
                            $record->loadMissing(
                                'prerequisites'
                            );

                            return [
                                'prerequisites' =>
                                $record
                                    ->prerequisites
                                    ->map(
                                        fn(
                                            Course $prerequisite
                                        ): array => [
                                            'course_id' =>
                                            $prerequisite->id,

                                            'requirement_type' =>
                                            $prerequisite
                                                ->pivot
                                                ?->requirement_type,
                                        ]
                                    )
                                    ->values()
                                    ->all(),
                            ];
                        }
                    )
                    ->schema(
                        fn(
                            Course $record
                        ): array => [
                            Repeater::make(
                                'prerequisites'
                            )
                                ->label(
                                    'Prerequisite Courses'
                                )
                                ->schema([
                                    Select::make(
                                        'course_id'
                                    )
                                        ->label(
                                            'Prerequisite Course'
                                        )
                                        ->options(
                                            static::prerequisiteCourseOptions(
                                                $record
                                            )
                                        )
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->helperText(
                                            'The current Course cannot be selected. Circular prerequisite chains are rejected by LCMS.'
                                        ),

                                    TextInput::make(
                                        'requirement_type'
                                    )
                                        ->label(
                                            'Requirement Type'
                                        )
                                        ->maxLength(50)
                                        ->placeholder(
                                            'Optional'
                                        )
                                        ->helperText(
                                            'Optional free-text classification. Maximum 50 characters.'
                                        ),
                                ])
                                ->columns(2)
                                ->default([])
                                ->reorderable(false)
                                ->addActionLabel(
                                    'Add Prerequisite'
                                )
                                ->columnSpanFull(),
                        ]
                    )
                    ->action(
                        function (
                            Course $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Prerequisites could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            $rows =
                                $data['prerequisites'] ?? [];

                            if (! is_array($rows)) {
                                static::failure(
                                    'Prerequisites could not be updated.',
                                    'The prerequisite data is invalid.'
                                );

                                return;
                            }

                            $prerequisites = [];

                            foreach (
                                $rows as $row
                            ) {
                                if (! is_array($row)) {
                                    static::failure(
                                        'Prerequisites could not be updated.',
                                        'A prerequisite entry is invalid.'
                                    );

                                    return;
                                }

                                $prerequisites[] = [
                                    'course_id' =>
                                    $row['course_id'] ?? null,

                                    'requirement_type' =>
                                    $row['requirement_type'] ?? null,
                                ];
                            }

                            try {
                                app(
                                    AcademicCatalogManagementService::class
                                )->replacePrerequisites(
                                    $actor,
                                    $record,
                                    $prerequisites
                                );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::failure(
                                    'Prerequisites could not be updated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Prerequisites could not be updated.',
                                    'An unexpected error occurred while updating Course prerequisites.'
                                );

                                return;
                            }

                            $record->unsetRelation(
                                'prerequisites'
                            );

                            Notification::make()
                                ->title(
                                    'Course prerequisites updated'
                                )
                                ->body(
                                    'The complete prerequisite set was saved successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'archiveCourse'
                )
                    ->label('Archive')
                    ->color('danger')
                    ->visible(
                        fn(
                            Course $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            ===
                            AcademicRecordStatus
                            ::Active
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Archive Course'
                    )
                    ->modalDescription(
                        'The Course will no longer be available for new academic operations. Historical classes and prerequisite relationships remain preserved.'
                    )
                    ->modalSubmitActionLabel(
                        'Archive Course'
                    )
                    ->action(
                        fn(
                            Course $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            false
                        )
                    ),

                Action::make(
                    'restoreCourse'
                )
                    ->label('Restore')
                    ->color('success')
                    ->visible(
                        function (
                            Course $record
                        ): bool {
                            if (
                                ! static::canView(
                                    $record
                                )
                                || $record->status
                                !==
                                AcademicRecordStatus
                                ::Archived
                            ) {
                                return false;
                            }

                            $record->loadMissing([
                                'language',
                                'academicLevel',
                            ]);

                            return $record
                                ->language
                                ?->status
                                ===
                                AcademicRecordStatus
                                ::Active
                                && $record
                                ->academicLevel
                                ?->status
                                ===
                                AcademicRecordStatus
                                ::Active;
                        }
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Restore Course'
                    )
                    ->modalDescription(
                        'The Course will return to Active status. Its parent Language and Academic Level must both be Active.'
                    )
                    ->modalSubmitActionLabel(
                        'Restore Course'
                    )
                    ->action(
                        fn(
                            Course $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            true
                        )
                    ),
            ]),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Course::withoutGlobalScopes()
            ->with([
                'language',
                'academicLevel',
            ])
            ->withCount([
                'prerequisites',
                'requiredByCourses',
                'courseClasses',
            ]);

        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        return $query->where(
            'center_id',
            $centerId
        );
    }

    public static function canViewAny(): bool
    {
        return static::authorizedCenterId()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record instanceof Course
        ) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->exists();
    }

    /*
     * Native CRUD is disabled.
     *
     * All Course mutations use
     * AcademicCatalogManagementService.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(
        Model $record
    ): bool {
        return false;
    }

    public static function canDelete(
        Model $record
    ): bool {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' =>
            ListCourses::route('/'),

            'view' =>
            ViewCourse::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function courseFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Course Name')
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label('Course Code')
                ->required()
                ->maxLength(50),

            TextInput::make(
                'duration_weeks'
            )
                ->label(
                    'Duration (Weeks)'
                )
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required(),

            TextInput::make(
                'total_hours'
            )
                ->label(
                    'Total Hours'
                )
                ->numeric()
                ->step(0.01)
                ->minValue(0.01)
                ->maxValue(999999.99)
                ->required(),

            TextInput::make(
                'default_fee'
            )
                ->label(
                    'Default Fee'
                )
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(
                    9999999999.99
                )
                ->required(),

            TextInput::make(
                'passing_grade'
            )
                ->label(
                    'Passing Grade (%)'
                )
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(100)
                ->required(),

            TextInput::make(
                'minimum_attendance'
            )
                ->label(
                    'Minimum Attendance (%)'
                )
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(100)
                ->required(),

            Textarea::make(
                'description'
            )
                ->label(
                    'Description'
                )
                ->rows(4)
                ->maxLength(65535)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function languageOptions(): array
    {
        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return [];
        }

        return \App\Models\Language
            ::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->orderBy('name')
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function academicLevelOptions(
        bool $activeOnly = false
    ): array {
        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return [];
        }

        $query =
            AcademicLevel
            ::withoutGlobalScopes()
            ->with('language')
            ->where(
                'center_id',
                $centerId
            );

        if ($activeOnly) {
            $query
                ->where(
                    'status',
                    AcademicRecordStatus
                    ::Active
                        ->value
                )
                ->whereHas(
                    'language',
                    fn(
                        Builder $query
                    ): Builder =>
                    $query->where(
                        'status',
                        AcademicRecordStatus
                        ::Active
                            ->value
                    )
                );
        }

        return $query
            ->orderBy(
                'sequence_number'
            )
            ->get()
            ->mapWithKeys(
                function (
                    AcademicLevel $level
                ): array {
                    $language =
                        $level
                        ->language
                        ?->name
                        ?? 'Unknown Language';

                    return [
                        $level->id =>
                        $language
                            . ' · '
                            . $level->name,
                    ];
                }
            )
            ->all();
    }

    public static function resolveCreationAcademicLevel(
        mixed $academicLevelId
    ): ?AcademicLevel {
        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return null;
        }

        $academicLevelId =
            filter_var(
                $academicLevelId,
                FILTER_VALIDATE_INT
            );

        if (
            $academicLevelId === false
            || $academicLevelId <= 0
        ) {
            return null;
        }

        return AcademicLevel
            ::withoutGlobalScopes()
            ->with('language')
            ->whereKey(
                $academicLevelId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                AcademicRecordStatus
                ::Active
                    ->value
            )
            ->whereHas(
                'language',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'status',
                    AcademicRecordStatus
                    ::Active
                        ->value
                )
            )
            ->first();
    }

    /**
     * @return array<int|string, string>
     */
    public static function prerequisiteCourseOptions(
        Course $course
    ): array {
        $centerId =
            static::authorizedCenterId();

        if (
            $centerId === null
            || ! $course->exists
            || $course->getKey() === null
        ) {
            return [];
        }

        return Course::withoutGlobalScopes()
            ->with([
                'language',
                'academicLevel',
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->whereKeyNot(
                $course->getKey()
            )
            ->orderBy(
                'language_id'
            )
            ->orderBy(
                'academic_level_id'
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(
                function (
                    Course $candidate
                ): array {
                    $language =
                        $candidate
                        ->language
                        ?->name
                        ?? 'Unknown Language';

                    $level =
                        $candidate
                        ->academicLevel
                        ?->name
                        ?? 'Unknown Level';

                    $status =
                        $candidate->status
                        ===
                        AcademicRecordStatus
                        ::Archived
                        ? 'Archived'
                        : 'Active';

                    return [
                        $candidate->id =>
                        $language
                            . ' · '
                            . $level
                            . ' · '
                            . $candidate->name
                            . ' ['
                            . $status
                            . ']',
                    ];
                }
            )
            ->all();
    }

    private static function authorizedCenterId(): ?int
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id
            === null
        ) {
            return null;
        }

        if (
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            return null;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission
                ::ManageAcademicStructure
            )
        ) {
            return null;
        }

        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant->isCenterScoped()
            || $tenant->centerId()
            !== $actor->center_id
        ) {
            return null;
        }

        if (
            ! app(
                BranchContext::class
            )->isCenterWide()
        ) {
            return null;
        }

        return (int)
        $actor->center_id;
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function changeLifecycle(
        Course $record,
        bool $restore
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Course lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    AcademicCatalogManagementService::class
                );

            if ($restore) {
                $service->restoreCourse(
                    $actor,
                    $record
                );
            } else {
                $service->archiveCourse(
                    $actor,
                    $record
                );
            }
        } catch (
            AuthorizationException
            | DomainException
            | InvalidArgumentException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Course lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (
            Throwable) {
            static::failure(
                'Course lifecycle could not be changed.',
                'An unexpected error occurred while changing the Course lifecycle.'
            );

            return;
        }

        Notification::make()
            ->title(
                $restore
                    ? 'Course restored'
                    : 'Course archived'
            )
            ->success()
            ->send();
    }

    private static function statusLabel(
        mixed $state
    ): string {
        $status =
            $state
            instanceof AcademicRecordStatus
            ? $state
            : AcademicRecordStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            AcademicRecordStatus::Active =>
            'Active',

            AcademicRecordStatus::Archived =>
            'Archived',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state
            instanceof AcademicRecordStatus
            ? $state
            : AcademicRecordStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            AcademicRecordStatus::Active =>
            'success',

            AcademicRecordStatus::Archived =>
            'gray',

            default =>
            'gray',
        };
    }

    private static function failure(
        string $title,
        string $message
    ): void {
        Notification::make()
            ->title($title)
            ->body($message)
            ->danger()
            ->send();
    }
}