<?php

namespace App\Filament\Resources\ClassSessions;

use App\Filament\Resources\ClassSessions\Pages\ListClassSessions;
use App\Filament\Resources\ClassSessions\Pages\ViewClassSession;
use App\Models\ClassSession;
use App\Models\User;
use App\Support\Enums\ClassSessionStatus;
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
use App\Services\Scheduling\SessionManagementService;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\StaffStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Textarea;

class ClassSessionResource extends Resource
{
    protected static ?string $model =
    ClassSession::class;

    protected static ?string $navigationLabel =
    'Class Sessions';

    protected static ?string $modelLabel =
    'Class Session';

    protected static ?string $pluralModelLabel =
    'Class Sessions';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Session'
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
                            'session_status'
                        )
                            ->label(
                                'Status'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusLabel(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'occurrence_date'
                        )
                            ->label(
                                'Original Occurrence'
                            )
                            ->date(
                                'Y-m-d'
                            ),

                        TextEntry::make(
                            'session_date'
                        )
                            ->label(
                                'Session Date'
                            )
                            ->date(
                                'Y-m-d'
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
                            'topic'
                        )
                            ->label(
                                'Topic'
                            )
                            ->placeholder(
                                'No topic'
                            ),

                        TextEntry::make(
                            'cancellation_reason'
                        )
                            ->label(
                                'Cancellation Reason'
                            )
                            ->placeholder(
                                'Not cancelled'
                            )
                            ->columnSpanFull(),
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

                        TextEntry::make(
                            'schedule_id'
                        )
                            ->label(
                                'Parent Schedule'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                'Schedule #'
                                    . (string) $state
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
                    ->searchable(),

                TextColumn::make(
                    'courseClass.branch.name'
                )
                    ->label(
                        'Branch'
                    ),

                TextColumn::make(
                    'session_date'
                )
                    ->label(
                        'Date'
                    )
                    ->date(
                        'Y-m-d'
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
                    'topic'
                )
                    ->label(
                        'Topic'
                    )
                    ->placeholder(
                        'No topic'
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
                    'session_status'
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
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'session_status'
                )
                    ->label(
                        'Status'
                    )
                    ->options(
                        collect(
                            ClassSessionStatus::cases()
                        )
                            ->mapWithKeys(
                                fn(
                                    ClassSessionStatus $status
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

                Action::make(
                    'updateSession'
                )
                    ->label(
                        'Update Session'
                    )
                    ->visible(
                        fn(
                            ClassSession $record
                        ): bool =>
                        $record->isScheduled()
                            && static::canView(
                                $record
                            )
                    )
                    ->modalHeading(
                        'Update Class Session'
                    )
                    ->modalDescription(
                        'Update the topic, Classroom, or Teacher for this scheduled Session.'
                    )
                    ->modalSubmitActionLabel(
                        'Update Session'
                    )
                    ->schema([
                        TextInput::make(
                            'topic'
                        )
                            ->label(
                                'Topic'
                            )
                            ->default(
                                fn(
                                    ClassSession $record
                                ): ?string =>
                                $record->topic
                            ),

                        Select::make(
                            'classroom_id'
                        )
                            ->label(
                                'Classroom'
                            )
                            ->options(
                                fn(
                                    ClassSession $record
                                ): array =>
                                static::classroomOptionsForSession(
                                    $record
                                )
                            )
                            ->default(
                                fn(
                                    ClassSession $record
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
                                    ClassSession $record
                                ): array =>
                                static::teacherOptionsForSession(
                                    $record
                                )
                            )
                            ->default(
                                fn(
                                    ClassSession $record
                                ): int =>
                                $record->teacher_id
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(
                        function (
                            ClassSession $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                $session =
                                    static::getEloquentQuery()
                                    ->whereKey(
                                        $record->getKey()
                                    )
                                    ->firstOrFail();

                                $classroom =
                                    static::resolveClassroomForSession(
                                        $session,
                                        (int) $data['classroom_id']
                                    );

                                $teacher =
                                    static::resolveTeacherForSession(
                                        $session,
                                        (int) $data['teacher_id']
                                    );

                                app(
                                    SessionManagementService::class
                                )->update(
                                    $actor,
                                    $session,
                                    [
                                        'topic' =>
                                        $data['topic']
                                            ?? null,

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
                                static::sessionActionFailure(
                                    'Session could not be updated.',
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Session updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),
                Action::make(
                    'rescheduleSession'
                )
                    ->label(
                        'Reschedule'
                    )
                    ->visible(
                        fn(
                            ClassSession $record
                        ): bool =>
                        $record->isScheduled()
                            && static::canView(
                                $record
                            )
                    )
                    ->modalHeading(
                        'Reschedule Class Session'
                    )
                    ->modalDescription(
                        'Change the date or time of this scheduled Class Session.'
                    )
                    ->modalSubmitActionLabel(
                        'Reschedule Session'
                    )
                    ->schema([
                        DatePicker::make(
                            'session_date'
                        )
                            ->label(
                                'Session Date'
                            )
                            ->default(
                                fn(
                                    ClassSession $record
                                ): string =>
                                $record
                                    ->session_date
                                    ->toDateString()
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
                                    ClassSession $record
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
                                    ClassSession $record
                                ): string =>
                                $record->end_time
                            )
                            ->required(),
                    ])
                    ->action(
                        function (
                            ClassSession $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be rescheduled.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                $session =
                                    static::getEloquentQuery()
                                    ->whereKey(
                                        $record->getKey()
                                    )
                                    ->firstOrFail();

                                app(
                                    SessionManagementService::class
                                )->reschedule(
                                    $actor,
                                    $session,
                                    [
                                        'session_date' =>
                                        (string) $data['session_date'],

                                        'start_time' =>
                                        (string) $data['start_time'],

                                        'end_time' =>
                                        (string) $data['end_time'],
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
                                static::sessionActionFailure(
                                    'Session could not be rescheduled.',
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Session rescheduled'
                                )
                                ->success()
                                ->send();
                        }
                    ),
                Action::make(
                    'cancelSession'
                )
                    ->label(
                        'Cancel'
                    )
                    ->color(
                        'danger'
                    )
                    ->requiresConfirmation()
                    ->visible(
                        fn(
                            ClassSession $record
                        ): bool =>
                        $record->isScheduled()
                            && static::canView(
                                $record
                            )
                    )
                    ->modalHeading(
                        'Cancel Class Session'
                    )
                    ->modalDescription(
                        'Cancel this Class Session while preserving its historical record.'
                    )
                    ->modalSubmitActionLabel(
                        'Cancel Session'
                    )
                    ->schema([
                        Textarea::make(
                            'reason'
                        )
                            ->label(
                                'Cancellation Reason'
                            )
                            ->required()
                            ->rows(3),
                    ])
                    ->action(
                        function (
                            ClassSession $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be cancelled.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                /*
                 * Re-resolve through the scoped Resource query
                 * before invoking the domain Service.
                 */
                                $session =
                                    static::getEloquentQuery()
                                    ->whereKey(
                                        $record->getKey()
                                    )
                                    ->firstOrFail();

                                app(
                                    SessionManagementService::class
                                )->cancel(
                                    $actor,
                                    $session,
                                    (string) $data['reason']
                                );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be cancelled.',
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Session cancelled'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'completeSession'
                )
                    ->label(
                        'Complete'
                    )
                    ->color(
                        'success'
                    )
                    ->requiresConfirmation()
                    ->visible(
                        fn(
                            ClassSession $record
                        ): bool =>
                        $record->isScheduled()
                            && static::canView(
                                $record
                            )
                    )
                    ->modalHeading(
                        'Complete Class Session'
                    )
                    ->modalDescription(
                        'Mark this Class Session as completed. This lifecycle change will be recorded in the audit history.'
                    )
                    ->modalSubmitActionLabel(
                        'Complete Session'
                    )
                    ->action(
                        function (
                            ClassSession $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be completed.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                /*
                 * Re-resolve through the scoped Resource query
                 * before invoking the domain Service.
                 */
                                $session =
                                    static::getEloquentQuery()
                                    ->whereKey(
                                        $record->getKey()
                                    )
                                    ->firstOrFail();

                                app(
                                    SessionManagementService::class
                                )->complete(
                                    $actor,
                                    $session
                                );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::sessionActionFailure(
                                    'Session could not be completed.',
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Session completed'
                                )
                                ->success()
                                ->send();
                        }
                    ),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            ClassSession::withoutGlobalScopes()
            ->with([
                'courseClass.branch',
                'schedule',
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
                instanceof ClassSession
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
            ListClassSessions::route(
                '/'
            ),

            'view' =>
            ViewClassSession::route(
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
    private static function classroomOptionsForSession(
        ClassSession $session
    ): array {
        if (
            ! static::canView(
                $session
            )
        ) {
            return [];
        }

        $courseClass =
            static::courseClassForSession(
                $session
            );

        if ($courseClass === null) {
            return [];
        }

        return Classroom::withoutGlobalScopes()
            ->where(
                'center_id',
                $session->center_id
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
    private static function teacherOptionsForSession(
        ClassSession $session
    ): array {
        if (
            ! static::canView(
                $session
            )
        ) {
            return [];
        }

        return Teacher::withoutGlobalScopes()
            ->with('person')
            ->where(
                'center_id',
                $session->center_id
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

    private static function resolveClassroomForSession(
        ClassSession $session,
        int $classroomId
    ): Classroom {
        if (
            ! static::canView(
                $session
            )
        ) {
            throw new AuthorizationException(
                'The Class Session is outside the authorized operational scope.'
            );
        }

        $courseClass =
            static::courseClassForSession(
                $session
            );

        if ($courseClass === null) {
            throw new ModelNotFoundException(
                'The Course Class for this Session could not be resolved.'
            );
        }

        return Classroom::withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->where(
                'center_id',
                $session->center_id
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

    private static function resolveTeacherForSession(
        ClassSession $session,
        int $teacherId
    ): Teacher {
        if (
            ! static::canView(
                $session
            )
        ) {
            throw new AuthorizationException(
                'The Class Session is outside the authorized operational scope.'
            );
        }

        return Teacher::withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->where(
                'center_id',
                $session->center_id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->firstOrFail();
    }

    private static function courseClassForSession(
        ClassSession $session
    ): ?CourseClass {
        return CourseClass::withoutGlobalScopes()
            ->whereKey(
                $session->class_id
            )
            ->where(
                'center_id',
                $session->center_id
            )
            ->first();
    }

    private static function sessionActionFailure(
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

    private static function statusLabel(
        mixed $state
    ): string {
        if (
            $state instanceof
            ClassSessionStatus
        ) {
            return $state->label();
        }

        $value =
            (string) (
                $state->value
                ?? $state
            );

        foreach (
            ClassSessionStatus::cases()
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
}