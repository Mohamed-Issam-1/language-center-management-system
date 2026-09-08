<?php

namespace App\Filament\Resources\ClassSchedules;

use App\Filament\Resources\ClassSchedules\Pages\ListClassSchedules;
use App\Filament\Resources\ClassSchedules\Pages\ViewClassSchedule;
use App\Models\ClassSchedule;
use App\Models\User;
use App\Support\Enums\ClassScheduleStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\Classroom;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Services\Scheduling\ScheduleManagementService;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\StaffStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use App\Services\Scheduling\SessionManagementService;
use Filament\Actions\ActionGroup;

class ClassScheduleResource extends Resource
{
    protected static ?string $model =
    ClassSchedule::class;

    protected static ?string $navigationLabel =
    'Class Schedules';

    protected static ?string $modelLabel =
    'Class Schedule';

    protected static ?string $pluralModelLabel =
    'Class Schedules';

    protected static string | \UnitEnum | null $navigationGroup =
    'Operations';

    protected static ?int $navigationSort =
    20;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Schedule'
                )
                    ->schema([
                        TextEntry::make(
                            'courseClass.class_code'
                        )
                            ->label(
                                'Class Code'
                            ),

                        TextEntry::make(
                            'courseClass.name'
                        )
                            ->label(
                                'Class Name'
                            ),

                        TextEntry::make(
                            'courseClass.branch.name'
                        )
                            ->label(
                                'Branch'
                            ),

                        TextEntry::make(
                            'status'
                        )
                            ->label(
                                'Schedule Status'
                            )
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
                            'day_of_week'
                        )
                            ->label(
                                'Day'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::dayLabel(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'start_time'
                        )
                            ->label(
                                'Start Time'
                            ),

                        TextEntry::make(
                            'end_time'
                        )
                            ->label(
                                'End Time'
                            ),

                        TextEntry::make(
                            'effective_from'
                        )
                            ->label(
                                'Effective From'
                            )
                            ->date(
                                'Y-m-d'
                            ),

                        TextEntry::make(
                            'effective_until'
                        )
                            ->label(
                                'Effective Until'
                            )
                            ->date(
                                'Y-m-d'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Resources'
                )
                    ->schema([
                        TextEntry::make(
                            'classroom.code'
                        )
                            ->label(
                                'Classroom Code'
                            ),

                        TextEntry::make(
                            'classroom.name'
                        )
                            ->label(
                                'Classroom'
                            ),

                        TextEntry::make(
                            'teacher.person.full_name'
                        )
                            ->label(
                                'Teacher'
                            ),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'courseClass.class_code'
                )
                    ->label(
                        'Class Code'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'courseClass.name'
                )
                    ->label(
                        'Class'
                    )
                    ->searchable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'courseClass.branch.name'
                )
                    ->label(
                        'Branch'
                    ),

                TextColumn::make(
                    'day_of_week'
                )
                    ->label(
                        'Day'
                    )
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::dayLabel(
                            $state
                        )
                    )
                    ->sortable(),

                TextColumn::make(
                    'start_time'
                )
                    ->label(
                        'Start'
                    ),

                TextColumn::make(
                    'end_time'
                )
                    ->label(
                        'End'
                    ),

                TextColumn::make(
                    'classroom.code'
                )
                    ->label(
                        'Classroom'
                    ),

                TextColumn::make(
                    'teacher.person.full_name'
                )
                    ->label(
                        'Teacher'
                    ),

                TextColumn::make(
                    'status'
                )
                    ->label(
                        'Status'
                    )
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
                    'effective_from'
                )
                    ->label(
                        'From'
                    )
                    ->date(
                        'Y-m-d'
                    )
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'effective_until'
                )
                    ->label(
                        'Until'
                    )
                    ->date(
                        'Y-m-d'
                    )
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Status'
                    )
                    ->options(
                        collect(
                            ClassScheduleStatus::cases()
                        )
                            ->mapWithKeys(
                                fn(
                                    ClassScheduleStatus $status
                                ): array => [
                                    $status->value =>
                                    $status->label(),
                                ]
                            )
                            ->all()
                    ),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([
                    Action::make(
                        'updateResources'
                    )
                        ->label(
                            'Update Resources'
                        )
                        ->visible(
                            fn(
                                ClassSchedule $record
                            ): bool =>
                            $record->isActive()
                                && static::canView(
                                    $record
                                )
                        )
                        ->modalHeading(
                            'Update Schedule Resources'
                        )
                        ->modalDescription(
                            'Change the Classroom or Teacher assigned to this Class Schedule.'
                        )
                        ->modalSubmitActionLabel(
                            'Update Resources'
                        )
                        ->schema([
                            Select::make(
                                'classroom_id'
                            )
                                ->label(
                                    'Classroom'
                                )
                                ->options(
                                    fn(
                                        ClassSchedule $record
                                    ): array =>
                                    static::classroomOptionsForSchedule(
                                        $record
                                    )
                                )
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): int =>
                                    $record->classroom_id
                                )
                                ->searchable()
                                ->preload()
                                ->required(),

                            Select::make(
                                'teacher_id'
                            )
                                ->label(
                                    'Teacher'
                                )
                                ->options(
                                    fn(
                                        ClassSchedule $record
                                    ): array =>
                                    static::teacherOptionsForSchedule(
                                        $record
                                    )
                                )
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): int =>
                                    $record->teacher_id
                                )
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(
                            function (
                                ClassSchedule $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule resources could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $classroom =
                                        static::resolveClassroomForSchedule(
                                            $record,
                                            (int) $data['classroom_id']
                                        );

                                    $teacher =
                                        static::resolveTeacherForSchedule(
                                            $record,
                                            (int) $data['teacher_id']
                                        );

                                    app(
                                        ScheduleManagementService::class
                                    )->update(
                                        $actor,
                                        $record,
                                        [
                                            'classroom_id' =>
                                            $classroom->id,

                                            'teacher_id' =>
                                            $teacher->id,
                                        ]
                                    );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | InvalidArgumentException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule resources could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Schedule resources updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),
                    Action::make(
                        'reschedule'
                    )
                        ->label(
                            'Reschedule'
                        )
                        ->visible(
                            fn(
                                ClassSchedule $record
                            ): bool =>
                            $record->isActive()
                                && static::canView(
                                    $record
                                )
                        )
                        ->modalHeading(
                            'Reschedule Class Schedule'
                        )
                        ->modalDescription(
                            'Change the recurring day, time, or effective period for this Class Schedule.'
                        )
                        ->modalSubmitActionLabel(
                            'Reschedule'
                        )
                        ->schema([
                            Select::make(
                                'day_of_week'
                            )
                                ->label(
                                    'Day of Week'
                                )
                                ->options([
                                    1 => 'Monday',
                                    2 => 'Tuesday',
                                    3 => 'Wednesday',
                                    4 => 'Thursday',
                                    5 => 'Friday',
                                    6 => 'Saturday',
                                    7 => 'Sunday',
                                ])
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): int =>
                                    $record->day_of_week
                                )
                                ->required(),

                            TimePicker::make(
                                'start_time'
                            )
                                ->label(
                                    'Start Time'
                                )
                                ->seconds(false)
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): string =>
                                    $record->start_time
                                )
                                ->required(),

                            TimePicker::make(
                                'end_time'
                            )
                                ->label(
                                    'End Time'
                                )
                                ->seconds(false)
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): string =>
                                    $record->end_time
                                )
                                ->required(),

                            DatePicker::make(
                                'effective_from'
                            )
                                ->label(
                                    'Effective From'
                                )
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): string =>
                                    $record
                                        ->effective_from
                                        ->toDateString()
                                )
                                ->required(),

                            DatePicker::make(
                                'effective_until'
                            )
                                ->label(
                                    'Effective Until'
                                )
                                ->default(
                                    fn(
                                        ClassSchedule $record
                                    ): string =>
                                    $record
                                        ->effective_until
                                        ->toDateString()
                                )
                                ->required(),
                        ])
                        ->action(
                            function (
                                ClassSchedule $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule could not be rescheduled.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    /*
                                    * Re-resolve the record through the scoped
                                    * Resource query before sending it to the
                                    * domain Service.
                                    */
                                    $schedule =
                                        static::getEloquentQuery()
                                        ->whereKey(
                                            $record->getKey()
                                        )
                                        ->firstOrFail();

                                    app(
                                        ScheduleManagementService::class
                                    )->reschedule(
                                        $actor,
                                        $schedule,
                                        [
                                            'day_of_week' =>
                                            (int) $data['day_of_week'],

                                            'start_time' =>
                                            (string) $data['start_time'],

                                            'end_time' =>
                                            (string) $data['end_time'],

                                            'effective_from' =>
                                            (string) $data['effective_from'],

                                            'effective_until' =>
                                            (string) $data['effective_until'],
                                        ]
                                    );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | InvalidArgumentException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule could not be rescheduled.',
                                        $exception->getMessage()
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Schedule rescheduled'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),
                    Action::make(
                        'cancelSchedule'
                    )
                        ->label(
                            'Cancel'
                        )
                        ->color(
                            'danger'
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Cancel Class Schedule'
                        )
                        ->modalDescription(
                            'This will cancel the recurring Class Schedule without deleting its historical record.'
                        )
                        ->modalSubmitActionLabel(
                            'Cancel Schedule'
                        )
                        ->visible(
                            fn(
                                ClassSchedule $record
                            ): bool =>
                            $record->isActive()
                                && static::canView(
                                    $record
                                )
                        )
                        ->action(
                            function (
                                ClassSchedule $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule could not be cancelled.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    /*
                 * Re-resolve through the scoped Resource query
                 * before invoking the domain Service.
                 */
                                    $schedule =
                                        static::getEloquentQuery()
                                        ->whereKey(
                                            $record->getKey()
                                        )
                                        ->firstOrFail();

                                    app(
                                        ScheduleManagementService::class
                                    )->cancel(
                                        $actor,
                                        $schedule
                                    );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | InvalidArgumentException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::scheduleActionFailure(
                                        'Schedule could not be cancelled.',
                                        $exception->getMessage()
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Schedule cancelled'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),
                    Action::make(
                        'generateSessions'
                    )
                        ->label(
                            'Generate Sessions'
                        )
                        ->color(
                            'success'
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Generate Class Sessions'
                        )
                        ->modalDescription(
                            'Generate any missing concrete Sessions for this recurring Class Schedule. Existing occurrences will be preserved.'
                        )
                        ->modalSubmitActionLabel(
                            'Generate Sessions'
                        )
                        ->visible(
                            fn(
                                ClassSchedule $record
                            ): bool =>
                            $record->isActive()
                                && static::canView(
                                    $record
                                )
                        )
                        ->action(
                            function (
                                ClassSchedule $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::scheduleActionFailure(
                                        'Sessions could not be generated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    /*
                 * Re-resolve the Schedule through the scoped
                 * Resource query before invoking the Service.
                 */
                                    $schedule =
                                        static::getEloquentQuery()
                                        ->whereKey(
                                            $record->getKey()
                                        )
                                        ->firstOrFail();

                                    $generated =
                                        app(
                                            SessionManagementService::class
                                        )->generateForSchedule(
                                            $actor,
                                            $schedule
                                        );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | InvalidArgumentException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::scheduleActionFailure(
                                        'Sessions could not be generated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Sessions generated'
                                    )
                                    ->body(
                                        $generated->count()
                                            . ' new Class Session(s) generated.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),
                ]),
            ])
            ->defaultSort(
                'effective_from',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            ClassSchedule::withoutGlobalScopes()
            ->with([
                'courseClass.branch',
                'classroom',
                'teacher.person',
            ]);

        if (
            ! static::actorCanAccessResource()
        ) {
            return static::denyQuery(
                $query
            );
        }

        $tenant =
            app(TenantContext::class);

        $centerId =
            $tenant->centerId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        $query->where(
            'center_id',
            $centerId
        );

        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return static::denyQuery(
                $query
            );
        }

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $query;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return static::denyQuery(
                $query
            );
        }

        $branchContext =
            app(BranchContext::class);

        $branchId =
            $branchContext->branchId();

        if (
            ! $branchContext
                ->isBranchScoped()
            || $branchId === null
        ) {
            return static::denyQuery(
                $query
            );
        }

        return $query
            ->whereHas(
                'courseClass',
                function (
                    Builder $courseClassQuery
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $courseClassQuery
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'branch_id',
                            $branchId
                        );
                }
            );
    }

    public static function canViewAny(): bool
    {
        return static::actorCanAccessResource();
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record
                instanceof ClassSchedule
        ) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->exists();
    }

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
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' =>
            ListClassSchedules::route(
                '/'
            ),

            'view' =>
            ViewClassSchedule::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanAccessResource(): bool
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageSchedules
            )
        ) {
            return false;
        }

        if (
            ! in_array(
                $actor->systemRole(),
                [
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                ],
                true
            )
        ) {
            return false;
        }

        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant
                ->isCenterScoped()
        ) {
            return false;
        }

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
        ) {
            return false;
        }

        $branchContext =
            app(BranchContext::class);

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $branchContext
                ->isCenterWide();
        }

        if (
            ! $branchContext
                ->isBranchScoped()
        ) {
            return false;
        }

        $branchId =
            $branchContext->branchId();

        if ($branchId === null) {
            return false;
        }

        return $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->exists();
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query
            ->whereRaw(
                '1 = 0'
            );
    }

    /**
     * @return array<int|string, string>
     */
    private static function classroomOptionsForSchedule(
        ClassSchedule $schedule
    ): array {
        if (
            ! static::canView(
                $schedule
            )
        ) {
            return [];
        }

        $courseClass =
            static::courseClassForSchedule(
                $schedule
            );

        if ($courseClass === null) {
            return [];
        }

        return Classroom::withoutGlobalScopes()
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->where(
                'branch_id',
                $courseClass->branch_id
            )
            ->where(
                'status',
                ClassroomStatus::Active
                    ->value
            )
            ->where(
                'availability_status',
                ClassroomAvailabilityStatus::Available
                    ->value
            )
            ->orderBy('code')
            ->get()
            ->mapWithKeys(
                fn(
                    Classroom $classroom
                ): array => [
                    $classroom->id =>
                    $classroom->code
                        . ' — '
                        . $classroom->name,
                ]
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private static function teacherOptionsForSchedule(
        ClassSchedule $schedule
    ): array {
        if (
            ! static::canView(
                $schedule
            )
        ) {
            return [];
        }

        return Teacher::withoutGlobalScopes()
            ->with('person')
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                fn(
                    Teacher $teacher
                ): array => [
                    $teacher->id =>
                    $teacher
                        ->person
                        ?->full_name
                        ?? 'Teacher #'
                        . $teacher->id,
                ]
            )
            ->all();
    }

    private static function resolveClassroomForSchedule(
        ClassSchedule $schedule,
        int $classroomId
    ): Classroom {
        if (
            ! static::canView(
                $schedule
            )
        ) {
            throw new AuthorizationException(
                'The Class Schedule is outside the authorized operational scope.'
            );
        }

        $courseClass =
            static::courseClassForSchedule(
                $schedule
            );

        if ($courseClass === null) {
            throw new ModelNotFoundException(
                'The Course Class for this Schedule could not be resolved.'
            );
        }

        return Classroom::withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->where(
                'branch_id',
                $courseClass->branch_id
            )
            ->where(
                'status',
                ClassroomStatus::Active
                    ->value
            )
            ->where(
                'availability_status',
                ClassroomAvailabilityStatus::Available
                    ->value
            )
            ->firstOrFail();
    }

    private static function resolveTeacherForSchedule(
        ClassSchedule $schedule,
        int $teacherId
    ): Teacher {
        if (
            ! static::canView(
                $schedule
            )
        ) {
            throw new AuthorizationException(
                'The Class Schedule is outside the authorized operational scope.'
            );
        }

        return Teacher::withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->firstOrFail();
    }

    private static function courseClassForSchedule(
        ClassSchedule $schedule
    ): ?CourseClass {
        return CourseClass::withoutGlobalScopes()
            ->whereKey(
                $schedule->class_id
            )
            ->where(
                'center_id',
                $schedule->center_id
            )
            ->first();
    }

    private static function scheduleActionFailure(
        string $title,
        string $message
    ): void {
        Notification::make()
            ->title(
                $title
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }

    private static function statusColor(
        mixed $state
    ): string {
        $value =
            strtolower(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($value) {
            'active' =>
            'success',

            'cancelled' =>
            'danger',

            'planned',
            'pending' =>
            'warning',

            'completed' =>
            'info',

            default =>
            'gray',
        };
    }

    private static function statusLabel(
        mixed $state
    ): string {
        if (
            $state instanceof
            ClassScheduleStatus
        ) {
            return $state->label();
        }

        $value =
            (string) (
                $state->value
                ?? $state
            );

        foreach (
            ClassScheduleStatus::cases()
            as $status
        ) {
            if (
                $status->value
                === $value
            ) {
                return $status->label();
            }
        }

        return ucfirst(
            $value
        );
    }

    private static function dayLabel(
        mixed $state
    ): string {
        return match ((int) $state) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',

            default =>
            'Unknown',
        };
    }
}