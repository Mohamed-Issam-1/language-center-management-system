<?php

namespace App\Filament\Resources\Attendances;

use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Pages\ViewAttendance;
use App\Models\Attendance;
use App\Models\User;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Services\Attendance\AttendanceManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Illuminate\Validation\ValidationException;

class AttendanceResource extends Resource
{
    protected static ?string $model =
    Attendance::class;

    protected static ?string $navigationLabel =
    'Attendance Records';

    protected static ?string $modelLabel =
    'Attendance Record';

    protected static ?string $pluralModelLabel =
    'Attendance Records';

    protected static string | \UnitEnum | null $navigationGroup =
    'Operations';

    protected static ?int $navigationSort =
    40;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Attendance'
                )
                    ->schema([
                        TextEntry::make(
                            'enrollment.student.person.full_name'
                        )
                            ->label(
                                'Student'
                            ),

                        TextEntry::make(
                            'session.courseClass.class_code'
                        )
                            ->label(
                                'Class Code'
                            ),

                        TextEntry::make(
                            'session.courseClass.name'
                        )
                            ->label(
                                'Class'
                            ),

                        TextEntry::make(
                            'session.courseClass.branch.name'
                        )
                            ->label(
                                'Branch'
                            ),

                        TextEntry::make(
                            'session.session_date'
                        )
                            ->label(
                                'Session Date'
                            )
                            ->date(
                                'Y-m-d'
                            ),

                        TextEntry::make(
                            'session.start_time'
                        )
                            ->label(
                                'Start Time'
                            ),

                        TextEntry::make(
                            'session.end_time'
                        )
                            ->label(
                                'End Time'
                            ),

                        TextEntry::make(
                            'attendanceStatus.name'
                        )
                            ->label(
                                'Attendance Status'
                            )
                            ->badge(),

                        TextEntry::make(
                            'attendanceStatus.code'
                        )
                            ->label(
                                'Status Code'
                            ),

                        TextEntry::make(
                            'late_minutes'
                        )
                            ->label(
                                'Late Minutes'
                            )
                            ->suffix(
                                ' min'
                            ),

                        TextEntry::make(
                            'excuse'
                        )
                            ->label(
                                'Excuse'
                            )
                            ->placeholder(
                                'No excuse'
                            ),

                        TextEntry::make(
                            'notes'
                        )
                            ->label(
                                'Notes'
                            )
                            ->placeholder(
                                'No notes'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(
                    'Recording Information'
                )
                    ->schema([
                        TextEntry::make(
                            'recordedBy.person.full_name'
                        )
                            ->label(
                                'Recorded By'
                            ),

                        TextEntry::make(
                            'recorded_at'
                        )
                            ->label(
                                'Recorded At'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            ),

                        TextEntry::make(
                            'updated_at'
                        )
                            ->label(
                                'Last Updated'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Not updated'
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
                    'enrollment.student.person.full_name'
                )
                    ->label(
                        'Student'
                    )
                    ->searchable(),

                TextColumn::make(
                    'session.courseClass.class_code'
                )
                    ->label(
                        'Class Code'
                    )
                    ->searchable(),

                TextColumn::make(
                    'session.courseClass.name'
                )
                    ->label(
                        'Class'
                    )
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'session.courseClass.branch.name'
                )
                    ->label(
                        'Branch'
                    )
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'session.session_date'
                )
                    ->label(
                        'Session Date'
                    )
                    ->date(
                        'Y-m-d'
                    )
                    ->sortable(),

                TextColumn::make(
                    'attendanceStatus.name'
                )
                    ->label(
                        'Status'
                    )
                    ->badge(),

                TextColumn::make(
                    'late_minutes'
                )
                    ->label(
                        'Late'
                    )
                    ->suffix(
                        ' min'
                    ),

                TextColumn::make(
                    'recordedBy.person.full_name'
                )
                    ->label(
                        'Recorded By'
                    )
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'recorded_at'
                )
                    ->label(
                        'Recorded At'
                    )
                    ->dateTime(
                        'Y-m-d H:i'
                    )
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make(
                    'correctAttendance'
                )
                    ->label(
                        'Correct Attendance'
                    )
                    ->color(
                        'warning'
                    )
                    ->visible(
                        fn(
                            Attendance $record
                        ): bool =>
                        static::actorCanCorrectAttendance(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Correct Attendance'
                    )
                    ->modalDescription(
                        'Correct the recorded Attendance result without changing its original recorder, recording time, Session, or Enrollment.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Correction'
                    )
                    ->schema([
                        Select::make(
                            'attendance_status_id'
                        )
                            ->label(
                                'Attendance Status'
                            )
                            ->options(
                                fn(
                                    Attendance $record
                                ): array =>
                                static::correctionStatusOptions(
                                    $record
                                )
                            )
                            ->default(
                                fn(
                                    Attendance $record
                                ): int =>
                                (int) $record
                                    ->attendance_status_id
                            )
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make(
                            'late_minutes'
                        )
                            ->label(
                                'Late Minutes'
                            )
                            ->numeric()
                            ->minValue(0)
                            ->default(
                                fn(
                                    Attendance $record
                                ): int =>
                                (int) $record
                                    ->late_minutes
                            )
                            ->required(),

                        TextInput::make(
                            'excuse'
                        )
                            ->label(
                                'Excuse'
                            )
                            ->maxLength(255)
                            ->default(
                                fn(
                                    Attendance $record
                                ): ?string =>
                                $record->excuse
                            ),

                        Textarea::make(
                            'notes'
                        )
                            ->label(
                                'Notes'
                            )
                            ->rows(3)
                            ->default(
                                fn(
                                    Attendance $record
                                ): ?string =>
                                $record->notes
                            ),
                    ])
                    ->action(
                        function (
                            Attendance $record,
                            array $data,
                            array $mountedActions
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor instanceof User
                            ) {
                                static::attendanceCorrectionFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            $lateMinutesValidator =
                                validator(
                                    [
                                        'late_minutes' =>
                                        $data['late_minutes']
                                            ?? null,
                                    ],
                                    [
                                        'late_minutes' => [
                                            'required',
                                            'integer',
                                            'min:0',
                                        ],
                                    ]
                                );

                            if (
                                $lateMinutesValidator->fails()
                            ) {
                                $mountedActionIndex =
                                    array_key_last(
                                        $mountedActions
                                    );

                                if (
                                    $mountedActionIndex === null
                                ) {
                                    $mountedActionIndex = 0;
                                }

                                throw ValidationException::withMessages([
                                    'mountedActions.'
                                        . $mountedActionIndex
                                        . '.data.late_minutes' =>
                                    $lateMinutesValidator
                                        ->errors()
                                        ->first(
                                            'late_minutes'
                                        ),
                                ]);
                            }

                            try {
                                /*
                     * Re-resolve the Attendance through the
                     * Resource scope immediately before the
                     * domain operation.
                     */
                                $attendance =
                                    static::resolveScopedAttendance(
                                        $record
                                    );

                                $status =
                                    static::resolveCorrectionStatus(
                                        (int) $data['attendance_status_id'],
                                        $attendance,
                                        $actor
                                    );

                                $attendance =
                                    app(
                                        AttendanceManagementService::class
                                    )->update(
                                        $actor,
                                        $attendance,
                                        [
                                            'attendance_status_id' =>
                                            $status->id,

                                            /*
                                 * Do not cast this value before
                                 * the domain service. The service
                                 * owns integer validation.
                                 */
                                            'late_minutes' =>
                                            (int) $data['late_minutes'],

                                            'excuse' =>
                                            $data['excuse']
                                                ?? null,

                                            'notes' =>
                                            $data['notes']
                                                ?? null,
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
                                static::attendanceCorrectionFailure(
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Attendance corrected'
                                )
                                ->body(
                                    'Attendance record #'
                                        . $attendance->id
                                        . ' was updated successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),
            ])
            ->defaultSort(
                'recorded_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Attendance::withoutGlobalScopes()
            ->with([
                'session.courseClass.branch',
                'enrollment.student.person',
                'enrollment.courseClass',
                'attendanceStatus',
                'recordedBy.person',
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
            ! $actor
                instanceof User
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

        /*
         * Require both sides of the Attendance pair to belong
         * to the manager's exact Class branch.
         *
         * This keeps malformed or inconsistent historical rows
         * fail-closed at the UI query boundary.
         */
        return $query
            ->whereHas(
                'session.courseClass',
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
            )
            ->whereHas(
                'enrollment.courseClass',
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
                instanceof Attendance
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
            ListAttendances::route(
                '/'
            ),

            'view' =>
            ViewAttendance::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanCorrectAttendance(
        Attendance $record
    ): bool {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageAttendance
            )
        ) {
            return false;
        }

        if (
            ! static::canView(
                $record
            )
        ) {
            return false;
        }

        /*
     * A cancelled Session cannot carry a changed
     * Attendance result.
     *
     * Enrollment terminal states are intentionally
     * not checked here because historical Attendance
     * remains correctable after completion,
     * withdrawal, or transfer.
     */
        $session =
            ClassSession::withoutGlobalScopes()
            ->whereKey(
                $record->session_id
            )
            ->where(
                'center_id',
                $record->center_id
            )
            ->first();

        return $session !== null
            && ! $session->isCancelled();
    }

    /**
     * @return array<int|string, string>
     */
    private static function correctionStatusOptions(
        Attendance $record
    ): array {
        if (
            ! static::actorCanCorrectAttendance(
                $record
            )
        ) {
            return [];
        }

        /*
     * Active statuses may be selected.
     *
     * The current historical status is also included
     * even when inactive, because the Backend permits
     * correcting notes/late minutes without replacing
     * that historical status.
     */
        return AttendanceStatus::withoutGlobalScopes()
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                function (
                    Builder $query
                ) use (
                    $record
                ): void {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orWhere(
                            'id',
                            $record
                                ->attendance_status_id
                        );
                }
            )
            ->orderBy(
                'name'
            )
            ->get()
            ->mapWithKeys(
                function (
                    AttendanceStatus $status
                ) use (
                    $record
                ): array {
                    $label =
                        $status->name
                        . ' ('
                        . $status->code
                        . ')';

                    if (
                        ! $status->isActive()
                        && $status->id
                        === $record
                        ->attendance_status_id
                    ) {
                        $label .=
                            ' — Current inactive status';
                    }

                    return [
                        $status->id =>
                        $label,
                    ];
                }
            )
            ->all();
    }

    private static function resolveScopedAttendance(
        Attendance $record
    ): Attendance {
        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->firstOrFail();
    }

    private static function resolveCorrectionStatus(
        int $statusId,
        Attendance $attendance,
        User $actor
    ): AttendanceStatus {
        $tenant =
            app(TenantContext::class);

        $centerId =
            $tenant->centerId();

        if (
            ! $tenant->isCenterScoped()
            || $centerId === null
            || $actor->center_id
            !== $centerId
            || $attendance->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        /*
     * The current status may remain selected even if
     * it has since been deactivated.
     *
     * Any replacement status must be active.
     */
        return AttendanceStatus::withoutGlobalScopes()
            ->whereKey(
                $statusId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                function (
                    Builder $query
                ) use (
                    $attendance
                ): void {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orWhere(
                            'id',
                            $attendance
                                ->attendance_status_id
                        );
                }
            )
            ->firstOrFail();
    }

    private static function attendanceCorrectionFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Attendance could not be corrected'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
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
                SystemPermission::ViewAttendance
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
}