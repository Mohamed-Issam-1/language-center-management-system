<?php

namespace App\Filament\Resources\CourseClasses;

use App\Filament\Resources\CourseClasses\Pages\ListCourseClasses;
use App\Filament\Resources\CourseClasses\Pages\ViewCourseClass;
use App\Models\Branch;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseClasses\CourseClassManagementService;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Filament\Actions\ActionGroup;

class CourseClassResource extends Resource
{
    protected static ?string $model =
    CourseClass::class;

    protected static ?string $navigationLabel =
    'Course Classes';

    protected static ?string $modelLabel =
    'Course Class';

    protected static ?string $pluralModelLabel =
    'Course Classes';

    protected static string | \UnitEnum | null $navigationGroup =
    'Academics';

    protected static ?int $navigationSort =
    40;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Class Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label(
                                'Course Class'
                            ),

                        TextEntry::make(
                            'class_code'
                        )
                            ->label(
                                'Class Code'
                            ),

                        TextEntry::make(
                            'course.name'
                        )
                            ->label('Course'),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label('Branch'),

                        TextEntry::make(
                            'class_status'
                        )
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
                            'delivery_mode'
                        )
                            ->label(
                                'Delivery Mode'
                            ),
                    ])
                    ->columns(3),

                Section::make(
                    'Operational Resources'
                )
                    ->schema([
                        TextEntry::make(
                            'assignedTeacher.person.full_name'
                        )
                            ->label('Teacher')
                            ->placeholder(
                                'Unavailable'
                            ),

                        TextEntry::make(
                            'assignedClassroom.name'
                        )
                            ->label(
                                'Classroom'
                            )
                            ->placeholder(
                                'Unavailable'
                            ),

                        TextEntry::make(
                            'capacity'
                        )
                            ->label('Capacity'),
                    ])
                    ->columns(3),

                Section::make(
                    'Period'
                )
                    ->schema([
                        TextEntry::make(
                            'start_date'
                        )
                            ->label(
                                'Start Date'
                            )
                            ->date('Y-m-d'),

                        TextEntry::make(
                            'end_date'
                        )
                            ->label(
                                'End Date'
                            )
                            ->date('Y-m-d'),
                    ])
                    ->columns(2),

                Section::make(
                    'Operational Summary'
                )
                    ->schema([
                        TextEntry::make(
                            'enrollments_count'
                        )
                            ->label(
                                'Enrollments'
                            ),

                        TextEntry::make(
                            'class_schedules_count'
                        )
                            ->label(
                                'Schedules'
                            ),

                        TextEntry::make(
                            'class_sessions_count'
                        )
                            ->label(
                                'Sessions'
                            ),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'class_code'
                )
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Class')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'course.name'
                )
                    ->label('Course')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'branch.name'
                )
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'assignedTeacher.person.full_name'
                )
                    ->label('Teacher')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'assignedClassroom.name'
                )
                    ->label('Classroom')
                    ->placeholder('—')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'capacity'
                )
                    ->label(
                        'Capacity'
                    )
                    ->sortable(),

                TextColumn::make(
                    'start_date'
                )
                    ->label('Starts')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make(
                    'end_date'
                )
                    ->label('Ends')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'delivery_mode'
                )
                    ->label(
                        'Delivery'
                    )
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'class_status'
                )
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
            ])
            ->filters([
                SelectFilter::make(
                    'branch_id'
                )
                    ->label('Branch')
                    ->options(
                        fn(): array =>
                        static::branchOptions()
                    ),

                SelectFilter::make(
                    'course_id'
                )
                    ->label('Course')
                    ->options(
                        fn(): array =>
                        static::courseOptions(
                            false
                        )
                    ),

                SelectFilter::make(
                    'class_status'
                )
                    ->label('Status')
                    ->options([
                        CourseClassStatus
                        ::Planned
                            ->value =>
                        'Planned',

                        CourseClassStatus
                        ::Active
                            ->value =>
                        'Active',

                        CourseClassStatus
                        ::Completed
                            ->value =>
                        'Completed',

                        CourseClassStatus
                        ::Cancelled
                            ->value =>
                        'Cancelled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'updateCourseClass'
                    )
                        ->label('Update')
                        ->color('gray')
                        ->visible(
                            fn(
                                CourseClass $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Update Course Class'
                        )
                        ->modalDescription(
                            'Update Class details and operational resource assignments. Center, Branch, Course, and lifecycle status cannot be changed here.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Changes'
                        )
                        ->fillForm(
                            fn(
                                CourseClass $record
                            ): array => [
                                'class_code' =>
                                $record
                                    ->class_code,

                                'name' =>
                                $record->name,

                                'assigned_classroom_id' =>
                                $record
                                    ->assigned_classroom_id,

                                'assigned_teacher_id' =>
                                $record
                                    ->assigned_teacher_id,

                                'start_date' =>
                                $record
                                    ->start_date
                                    ?->format(
                                        'Y-m-d'
                                    ),

                                'end_date' =>
                                $record
                                    ->end_date
                                    ?->format(
                                        'Y-m-d'
                                    ),

                                'capacity' =>
                                $record
                                    ->capacity,

                                'delivery_mode' =>
                                $record
                                    ->delivery_mode,
                            ]
                        )
                        ->schema(
                            fn(
                                CourseClass $record
                            ): array =>
                            static::updateFields(
                                $record
                            )
                        )
                        ->action(
                            function (
                                CourseClass $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Course Class could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                /*
                             * Send only actual changes.
                             *
                             * This matters for historical Classes:
                             * the Service intentionally revalidates
                             * Classroom operational state when
                             * capacity/Classroom is changed, and
                             * Teacher state when Teacher is changed.
                             */
                                $changes = [];

                                if (
                                    (string)
                                    $data['class_code']
                                    !==
                                    (string)
                                    $record
                                        ->class_code
                                ) {
                                    $changes['class_code'] =
                                        $data['class_code'];
                                }

                                if (
                                    (string)
                                    $data['name']
                                    !==
                                    (string)
                                    $record->name
                                ) {
                                    $changes['name'] =
                                        $data['name'];
                                }

                                if (
                                    (int)
                                    $data['assigned_classroom_id']
                                    !==
                                    (int)
                                    $record
                                        ->assigned_classroom_id
                                ) {
                                    $changes['assigned_classroom_id'] =
                                        (int)
                                        $data['assigned_classroom_id'];
                                }

                                if (
                                    (int)
                                    $data['assigned_teacher_id']
                                    !==
                                    (int)
                                    $record
                                        ->assigned_teacher_id
                                ) {
                                    $changes['assigned_teacher_id'] =
                                        (int)
                                        $data['assigned_teacher_id'];
                                }

                                $startDate =
                                    (string)
                                    $data['start_date'];

                                if (
                                    $startDate
                                    !==
                                    $record
                                    ->start_date
                                    ?->format(
                                        'Y-m-d'
                                    )
                                ) {
                                    $changes['start_date'] =
                                        $startDate;
                                }

                                $endDate =
                                    (string)
                                    $data['end_date'];

                                if (
                                    $endDate
                                    !==
                                    $record
                                    ->end_date
                                    ?->format(
                                        'Y-m-d'
                                    )
                                ) {
                                    $changes['end_date'] =
                                        $endDate;
                                }

                                if (
                                    (int)
                                    $data['capacity']
                                    !==
                                    (int)
                                    $record->capacity
                                ) {
                                    $changes['capacity'] =
                                        (int)
                                        $data['capacity'];
                                }

                                if (
                                    (string)
                                    $data['delivery_mode']
                                    !==
                                    (string)
                                    $record
                                        ->delivery_mode
                                ) {
                                    $changes['delivery_mode'] =
                                        $data['delivery_mode'];
                                }

                                try {
                                    app(
                                        CourseClassManagementService::class
                                    )->update(
                                        $actor,
                                        $record,
                                        $changes
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
                                        'Course Class could not be updated.',
                                        $exception
                                            ->getMessage()
                                    );

                                    return;
                                } catch (
                                    QueryException) {
                                    static::failure(
                                        'Course Class could not be updated.',
                                        'Database constraints rejected the change. Check Class Code uniqueness and selected operational resources.'
                                    );

                                    return;
                                } catch (
                                    Throwable) {
                                    static::failure(
                                        'Course Class could not be updated.',
                                        'An unexpected error occurred while updating the Course Class.'
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Course Class updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'activateCourseClass'
                    )
                        ->label('Activate')
                        ->color('success')
                        ->visible(
                            fn(
                                CourseClass $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->class_status
                                ===
                                CourseClassStatus::Planned
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Course Class'
                        )
                        ->modalDescription(
                            'Activation will revalidate the Branch, Course, Classroom, Teacher, capacity, and date range before the Class becomes operational.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Course Class'
                        )
                        ->action(
                            fn(
                                CourseClass $record
                            ) =>
                            static::transitionLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'completeCourseClass'
                    )
                        ->label('Complete')
                        ->color('primary')
                        ->visible(
                            fn(
                                CourseClass $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->class_status
                                ===
                                CourseClassStatus::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Complete Course Class'
                        )
                        ->modalDescription(
                            'Completing the Course Class closes its operational lifecycle. This action is only valid for an Active Class.'
                        )
                        ->modalSubmitActionLabel(
                            'Complete Course Class'
                        )
                        ->action(
                            fn(
                                CourseClass $record
                            ) =>
                            static::transitionLifecycle(
                                $record,
                                'complete'
                            )
                        ),

                    Action::make(
                        'cancelCourseClass'
                    )
                        ->label('Cancel')
                        ->color('danger')
                        ->visible(
                            fn(
                                CourseClass $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && in_array(
                                    $record->class_status,
                                    [
                                        CourseClassStatus::Planned,
                                        CourseClassStatus::Active,
                                    ],
                                    true
                                )
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Cancel Course Class'
                        )
                        ->modalDescription(
                            'Cancellation is final under the current LCMS lifecycle. Planned and Active Classes may be cancelled.'
                        )
                        ->modalSubmitActionLabel(
                            'Cancel Course Class'
                        )
                        ->action(
                            fn(
                                CourseClass $record
                            ) =>
                            static::transitionLifecycle(
                                $record,
                                'cancel'
                            )
                        ),
                ]),
            ])
            ->defaultSort(
                'start_date',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            CourseClass
            ::withoutGlobalScopes()
            ->with([
                'branch',
                'course',
                'assignedClassroom',
                'assignedTeacher.person',
            ])
            ->withCount([
                'enrollments',
                'classSchedules',
                'classSessions',
            ]);

        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return static::denyQuery(
                $query
            );
        }

        $query->where(
            'center_id',
            $scope['center_id']
        );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->where(
                'branch_id',
                $scope['branch_id']
            );
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::authorizedScope()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record
                instanceof CourseClass
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
     * All Course Class mutations use
     * CourseClassManagementService.
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
            ListCourseClasses
                ::route('/'),

            'view' =>
            ViewCourseClass
                ::route(
                    '/{record}'
                ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function updateFields(
        CourseClass $record
    ): array {
        return [
            TextInput::make(
                'class_code'
            )
                ->label('Class Code')
                ->required()
                ->maxLength(50),

            TextInput::make('name')
                ->label(
                    'Class Name'
                )
                ->required()
                ->maxLength(255),

            Select::make(
                'assigned_classroom_id'
            )
                ->label('Classroom')
                ->options(
                    fn(): array =>
                    static::classroomOptions(
                        $record->branch_id,
                        $record
                            ->assigned_classroom_id
                    )
                )
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),

            Select::make(
                'assigned_teacher_id'
            )
                ->label('Teacher')
                ->options(
                    fn(): array =>
                    static::teacherOptions(
                        $record
                            ->assigned_teacher_id
                    )
                )
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),

            DatePicker::make(
                'start_date'
            )
                ->label('Start Date')
                ->required(),

            DatePicker::make(
                'end_date'
            )
                ->label('End Date')
                ->required(),

            TextInput::make(
                'capacity'
            )
                ->label('Capacity')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required()
                ->helperText(
                    'Cannot exceed the assigned Classroom capacity.'
                ),

            TextInput::make(
                'delivery_mode'
            )
                ->label(
                    'Delivery Mode'
                )
                ->required()
                ->maxLength(50)
                ->helperText(
                    'LCMS currently defines no fixed Delivery Mode enum.'
                ),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function branchOptions(
        bool $activeOnly = false
    ): array {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        $query =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $scope['center_id']
            );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->whereKey(
                $scope['branch_id']
            );
        }

        if ($activeOnly) {
            $query->where(
                'status',
                BranchStatus::Active
                    ->value
            );
        }

        return $query
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
    public static function courseOptions(
        bool $activeOnly = true
    ): array {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        $query =
            Course::withoutGlobalScopes()
            ->where(
                'center_id',
                $scope['center_id']
            );

        if ($activeOnly) {
            $query->where(
                'status',
                AcademicRecordStatus
                ::Active
                    ->value
            );
        }

        return $query
            ->orderBy('name')
            ->get()
            ->mapWithKeys(
                fn(
                    Course $course
                ): array => [
                    $course->id =>
                    $course->code
                        . ' · '
                        . $course->name,
                ]
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function classroomOptions(
        ?int $branchId = null,
        ?int $includeId = null
    ): array {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        if (
            $scope['branch_id']
            !== null
        ) {
            $branchId =
                $scope['branch_id'];
        }

        $query =
            Classroom::withoutGlobalScopes()
            ->with('branch')
            ->where(
                'center_id',
                $scope['center_id']
            );

        if ($branchId !== null) {
            $query->where(
                'branch_id',
                $branchId
            );
        }

        $query->where(
            function (
                Builder $query
            ) use (
                $includeId
            ): void {
                $query->where(
                    function (
                        Builder $query
                    ): void {
                        $query
                            ->where(
                                'status',
                                ClassroomStatus
                                ::Active
                                    ->value
                            )
                            ->where(
                                'availability_status',
                                ClassroomAvailabilityStatus
                                ::Available
                                    ->value
                            );
                    }
                );

                if (
                    $includeId !== null
                ) {
                    $query->orWhere(
                        'id',
                        $includeId
                    );
                }
            }
        );

        return $query
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(
                function (
                    Classroom $classroom
                ): array {
                    $branchName =
                        $classroom
                        ->branch
                        ?->name
                        ?? 'Unknown Branch';

                    return [
                        $classroom->id =>
                        $branchName
                            . ' · '
                            . $classroom->name
                            . ' · capacity '
                            . $classroom
                            ->capacity,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function teacherOptions(
        ?int $includeId = null
    ): array {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        $query =
            Teacher::withoutGlobalScopes()
            ->with('person')
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                function (
                    Builder $query
                ) use (
                    $includeId
                ): void {
                    $query->where(
                        'status',
                        StaffStatus::Active
                            ->value
                    );

                    if (
                        $includeId !== null
                    ) {
                        $query->orWhere(
                            'id',
                            $includeId
                        );
                    }
                }
            );

        return $query
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                function (
                    Teacher $teacher
                ): array {
                    $name =
                        trim(
                            (string) (
                                $teacher
                                ->person
                                ?->full_name
                                ?? ''
                            )
                        );

                    if ($name === '') {
                        $name =
                            'Teacher #'
                            . $teacher->id;
                    }

                    return [
                        $teacher->id =>
                        $name,
                    ];
                }
            )
            ->all();
    }

    public static function resolveCreationBranch(
        mixed $branchId
    ): ?Branch {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return null;
        }

        if (
            $scope['branch_id']
            !== null
        ) {
            return Branch
                ::withoutGlobalScopes()
                ->whereKey(
                    $scope['branch_id']
                )
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->where(
                    'status',
                    BranchStatus::Active
                        ->value
                )
                ->first();
        }

        $branchId =
            filter_var(
                $branchId,
                FILTER_VALIDATE_INT
            );

        if (
            $branchId === false
            || $branchId <= 0
        ) {
            return null;
        }

        return Branch
            ::withoutGlobalScopes()
            ->whereKey(
                $branchId
            )
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'status',
                BranchStatus::Active
                    ->value
            )
            ->first();
    }

    public static function resolveCreationCourse(
        mixed $courseId
    ): ?Course {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return null;
        }

        $courseId =
            filter_var(
                $courseId,
                FILTER_VALIDATE_INT
            );

        if (
            $courseId === false
            || $courseId <= 0
        ) {
            return null;
        }

        return Course
            ::withoutGlobalScopes()
            ->whereKey(
                $courseId
            )
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'status',
                AcademicRecordStatus
                ::Active
                    ->value
            )
            ->first();
    }

    public static function resolveCreationClassroom(
        mixed $classroomId,
        Branch $branch
    ): ?Classroom {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return null;
        }

        $classroomId =
            filter_var(
                $classroomId,
                FILTER_VALIDATE_INT
            );

        if (
            $classroomId === false
            || $classroomId <= 0
        ) {
            return null;
        }

        return Classroom
            ::withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->where(
                'status',
                ClassroomStatus::Active
                    ->value
            )
            ->where(
                'availability_status',
                ClassroomAvailabilityStatus
                ::Available
                    ->value
            )
            ->first();
    }

    public static function resolveCreationTeacher(
        mixed $teacherId
    ): ?Teacher {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return null;
        }

        $teacherId =
            filter_var(
                $teacherId,
                FILTER_VALIDATE_INT
            );

        if (
            $teacherId === false
            || $teacherId <= 0
        ) {
            return null;
        }

        return Teacher
            ::withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->first();
    }

    /**
     * @return array{
     *     center_id:int,
     *     branch_id:?int
     * }|null
     */
    private static function authorizedScope(): ?array
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
            ! $actor->hasPermission(
                SystemPermission
                ::ManageClasses
            )
        ) {
            return null;
        }

        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant
                ->isCenterScoped()
            || $tenant->centerId()
            !== $actor->center_id
        ) {
            return null;
        }

        $branchContext =
            app(
                BranchContext::class
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $branchContext
                    ->isCenterWide()
            ) {
                return null;
            }

            return [
                'center_id' =>
                $actor->center_id,

                'branch_id' =>
                null,
            ];
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
            || ! $branchContext
                ->isBranchScoped()
        ) {
            return null;
        }

        $branch =
            $branchContext->branch();

        if (
            $branch === null
            || $branch->center_id
            !== $actor->center_id
        ) {
            return null;
        }

        $hasAssignment =
            $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $actor->center_id
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->exists();

        if (! $hasAssignment) {
            return null;
        }

        return [
            'center_id' =>
            $actor->center_id,

            'branch_id' =>
            $branch->id,
        ];
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function transitionLifecycle(
        CourseClass $record,
        string $transition
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Course Class lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    CourseClassManagementService::class
                );

            match ($transition) {
                'activate' =>
                $service->activate(
                    $actor,
                    $record
                ),

                'complete' =>
                $service->complete(
                    $actor,
                    $record
                ),

                'cancel' =>
                $service->cancel(
                    $actor,
                    $record
                ),

                default =>
                throw new LogicException(
                    'Unsupported Course Class lifecycle transition.'
                ),
            };
        } catch (
            AuthorizationException
            | DomainException
            | InvalidArgumentException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Course Class lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Course Class lifecycle could not be changed.',
                'An unexpected error occurred while changing the Course Class lifecycle.'
            );

            return;
        }

        $record->refresh();

        $title = match ($transition) {
            'activate' =>
            'Course Class activated',

            'complete' =>
            'Course Class completed',

            'cancel' =>
            'Course Class cancelled',

            default =>
            'Course Class updated',
        };

        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }

    private static function statusLabel(
        mixed $state
    ): string {
        $status =
            $state
            instanceof CourseClassStatus
            ? $state
            : CourseClassStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return $status?->label()
            ?? 'Unknown';
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state
            instanceof CourseClassStatus
            ? $state
            : CourseClassStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            CourseClassStatus::Planned =>
            'warning',

            CourseClassStatus::Active =>
            'success',

            CourseClassStatus::Completed =>
            'gray',

            CourseClassStatus::Cancelled =>
            'danger',

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