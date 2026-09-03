<?php

namespace App\Filament\Resources\Enrollments;

use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Enums\EnrollmentStatus;
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
use App\Services\Enrollment\EnrollmentManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use App\Models\CourseClass;
use App\Support\Enums\CourseClassStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;

class EnrollmentResource extends Resource
{
    protected static ?string $model =
    Enrollment::class;

    protected static ?string $navigationLabel =
    'Enrollments';

    protected static ?string $modelLabel =
    'Enrollment';

    protected static ?string $pluralModelLabel =
    'Enrollments';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make('Enrollment')
                    ->schema([
                        TextEntry::make(
                            'enrollment_number'
                        )
                            ->label(
                                'Enrollment Number'
                            ),

                        TextEntry::make(
                            'enrollment_status'
                        )
                            ->label('Status')
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusLabel(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'enrollment_date'
                        )
                            ->label(
                                'Enrollment Date'
                            )
                            ->date('Y-m-d'),

                        TextEntry::make(
                            'eligibility_status'
                        )
                            ->label(
                                'Eligibility'
                            ),

                        TextEntry::make(
                            'withdrawal_date'
                        )
                            ->label(
                                'Withdrawal Date'
                            )
                            ->date('Y-m-d')
                            ->placeholder(
                                'Not withdrawn'
                            ),

                        TextEntry::make(
                            'withdrawal_reason'
                        )
                            ->label(
                                'Withdrawal Reason'
                            )
                            ->placeholder(
                                'Not provided'
                            ),
                    ])
                    ->columns(2),

                Section::make('Student')
                    ->schema([
                        TextEntry::make(
                            'student.person.full_name'
                        )
                            ->label(
                                'Student Name'
                            ),

                        TextEntry::make(
                            'student.person.national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'student.branch.name'
                        )
                            ->label(
                                'Student Branch'
                            ),
                    ])
                    ->columns(2),

                Section::make('Course Class')
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
                                'Class Branch'
                            ),

                        TextEntry::make(
                            'courseClass.class_status'
                        )
                            ->label(
                                'Class Status'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                is_object($state)
                                    && method_exists(
                                        $state,
                                        'label'
                                    )
                                    ? $state->label()
                                    : ucfirst(
                                        (string) (
                                            $state->value
                                            ?? $state
                                        )
                                    )
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Enrollment History'
                )
                    ->schema([
                        RepeatableEntry::make(
                            'histories'
                        )
                            ->label('')
                            ->schema([
                                TextEntry::make(
                                    'event_type'
                                )
                                    ->label(
                                        'Event'
                                    )
                                    ->formatStateUsing(
                                        fn(
                                            mixed $state
                                        ): string =>
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                (string) $state
                                            )
                                        )
                                    ),

                                TextEntry::make(
                                    'previous_status'
                                )
                                    ->label(
                                        'Previous Status'
                                    )
                                    ->formatStateUsing(
                                        fn(
                                            mixed $state
                                        ): string =>
                                        static::statusLabel(
                                            $state
                                        )
                                    )
                                    ->placeholder(
                                        'Initial'
                                    ),

                                TextEntry::make(
                                    'new_status'
                                )
                                    ->label(
                                        'New Status'
                                    )
                                    ->formatStateUsing(
                                        fn(
                                            mixed $state
                                        ): string =>
                                        static::statusLabel(
                                            $state
                                        )
                                    )
                                    ->placeholder(
                                        'Not specified'
                                    ),

                                TextEntry::make(
                                    'performedBy.account_login_identifier'
                                )
                                    ->label(
                                        'Performed By'
                                    )
                                    ->placeholder(
                                        'Unknown User'
                                    ),

                                TextEntry::make(
                                    'occurred_at'
                                )
                                    ->label(
                                        'Occurred At'
                                    )
                                    ->dateTime(
                                        'Y-m-d H:i:s'
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
                    ]),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'enrollment_number'
                )
                    ->label(
                        'Enrollment Number'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'student.person.full_name'
                )
                    ->label('Student')
                    ->searchable(),

                TextColumn::make(
                    'courseClass.class_code'
                )
                    ->label(
                        'Class Code'
                    )
                    ->searchable(),

                TextColumn::make(
                    'courseClass.name'
                )
                    ->label(
                        'Class'
                    ),

                TextColumn::make(
                    'courseClass.branch.name'
                )
                    ->label(
                        'Branch'
                    ),

                TextColumn::make(
                    'enrollment_status'
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
                    ),

                TextColumn::make(
                    'enrollment_date'
                )
                    ->label(
                        'Enrollment Date'
                    )
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make(
                    'enrollment_status'
                )
                    ->label('Status')
                    ->options(
                        collect(
                            EnrollmentStatus::cases()
                        )
                            ->mapWithKeys(
                                fn(
                                    EnrollmentStatus $status
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

                Action::make('withdraw')
                    ->label('Withdraw')
                    ->color('danger')
                    ->visible(
                        fn(
                            Enrollment $record
                        ): bool =>
                        $record->isActive()
                            && static::canView(
                                $record
                            )
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Withdraw Enrollment'
                    )
                    ->modalDescription(
                        'This will mark the active Enrollment as withdrawn. The operation will be recorded in Enrollment History and Audit.'
                    )
                    ->modalSubmitActionLabel(
                        'Withdraw Enrollment'
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label(
                                'Withdrawal Reason'
                            )
                            ->maxLength(255)
                            ->rows(3),
                    ])
                    ->action(
                        function (
                            Enrollment $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be withdrawn'
                                    )
                                    ->body(
                                        'The authenticated User Account could not be resolved.'
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $enrollment =
                                    app(
                                        EnrollmentManagementService::class
                                    )->withdraw(
                                        $actor,
                                        $record,
                                        $data['reason']
                                            ?? null
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be withdrawn'
                                    )
                                    ->body(
                                        $exception
                                            ->getMessage()
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Enrollment withdrawn'
                                )
                                ->body(
                                    'Enrollment '
                                        . $enrollment
                                        ->enrollment_number
                                        . ' was withdrawn successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),
                Action::make('transfer')
                    ->label('Transfer')
                    ->color('warning')
                    ->visible(
                        fn(
                            Enrollment $record
                        ): bool =>
                        $record->isActive()
                            && static::canView(
                                $record
                            )
                    )
                    ->modalHeading(
                        'Transfer Enrollment'
                    )
                    ->modalDescription(
                        'Transfer this Student to another eligible Course Class. The current Enrollment will remain as historical Transferred data and a new active Enrollment will be created.'
                    )
                    ->modalSubmitActionLabel(
                        'Transfer Enrollment'
                    )
                    ->schema([
                        Select::make(
                            'target_class_id'
                        )
                            ->label(
                                'Target Course Class'
                            )
                            ->options(
                                fn(
                                    Enrollment $record
                                ): array =>
                                static::transferTargetClassOptions(
                                    $record
                                )
                            )
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make(
                            'enrollment_number'
                        )
                            ->label(
                                'New Enrollment Number'
                            )
                            ->required()
                            ->maxLength(50),

                        DatePicker::make(
                            'enrollment_date'
                        )
                            ->label(
                                'New Enrollment Date'
                            )
                            ->default(
                                now()->toDateString()
                            )
                            ->required(),
                    ])
                    ->action(
                        function (
                            Enrollment $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be transferred'
                                    )
                                    ->body(
                                        'The authenticated User Account could not be resolved.'
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $targetClass =
                                    static::resolveTransferTargetClass(
                                        $record,
                                        (int) $data['target_class_id'],
                                        $actor
                                    );

                                $targetEnrollment =
                                    app(
                                        EnrollmentManagementService::class
                                    )->transfer(
                                        $actor,
                                        $record,
                                        $targetClass,
                                        [
                                            'enrollment_number' =>
                                            (string) $data['enrollment_number'],

                                            'enrollment_date' =>
                                            (string) $data['enrollment_date'],
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
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be transferred'
                                    )
                                    ->body(
                                        $exception->getMessage()
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Enrollment transferred'
                                )
                                ->body(
                                    'New Enrollment '
                                        . $targetEnrollment
                                        ->enrollment_number
                                        . ' was created successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('complete')
                    ->label('Complete')
                    ->color('success')
                    ->visible(
                        fn(
                            Enrollment $record
                        ): bool =>
                        $record->isActive()
                            && static::canView(
                                $record
                            )
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Complete Enrollment'
                    )
                    ->modalDescription(
                        'This will mark the active Enrollment as completed. This is a terminal lifecycle state.'
                    )
                    ->modalSubmitActionLabel(
                        'Complete Enrollment'
                    )
                    ->action(
                        function (
                            Enrollment $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be completed'
                                    )
                                    ->body(
                                        'The authenticated User Account could not be resolved.'
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $enrollment =
                                    app(
                                        EnrollmentManagementService::class
                                    )->updateStatus(
                                        $actor,
                                        $record,
                                        EnrollmentStatus::Completed
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be completed'
                                    )
                                    ->body(
                                        $exception->getMessage()
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Enrollment completed'
                                )
                                ->body(
                                    'Enrollment '
                                        . $enrollment
                                        ->enrollment_number
                                        . ' was completed successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->visible(
                        fn(
                            Enrollment $record
                        ): bool =>
                        $record->isActive()
                            && static::canView(
                                $record
                            )
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Cancel Enrollment'
                    )
                    ->modalDescription(
                        'This will permanently mark the active Enrollment as cancelled. This is a terminal lifecycle state.'
                    )
                    ->modalSubmitActionLabel(
                        'Cancel Enrollment'
                    )
                    ->action(
                        function (
                            Enrollment $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be cancelled'
                                    )
                                    ->body(
                                        'The authenticated User Account could not be resolved.'
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $enrollment =
                                    app(
                                        EnrollmentManagementService::class
                                    )->updateStatus(
                                        $actor,
                                        $record,
                                        EnrollmentStatus::Cancelled
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                Notification::make()
                                    ->title(
                                        'Enrollment could not be cancelled'
                                    )
                                    ->body(
                                        $exception->getMessage()
                                    )
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Enrollment cancelled'
                                )
                                ->body(
                                    'Enrollment '
                                        . $enrollment
                                        ->enrollment_number
                                        . ' was cancelled successfully.'
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
            Enrollment::withoutGlobalScopes()
            ->with([
                'student.person',
                'student.branch',
                'courseClass.branch',

                'histories' =>
                fn(
                    $historyQuery
                ) =>
                $historyQuery
                    ->orderBy(
                        'occurred_at'
                    )
                    ->orderBy('id'),

                'histories.performedBy',
            ]);

        if (! static::actorCanAccessResource()) {
            return static::denyQuery(
                $query
            );
        }

        $user = auth()->user();

        if (! $user instanceof User) {
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
            'enrollments.center_id',
            $centerId
        );

        if (
            $user->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $query;
        }

        if (
            $user->systemRole()
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

        if ($branchId === null) {
            return static::denyQuery(
                $query
            );
        }

        /*
         * Branch Manager scope intentionally validates both sides
         * of the Enrollment relationship.
         *
         * A record is visible only when the Student and the Course
         * Class both belong to the exact assigned Branch.
         */
        return $query
            ->whereHas(
                'student',
                fn(
                    Builder $studentQuery
                ): Builder =>
                $studentQuery
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
            )
            ->whereHas(
                'courseClass',
                fn(
                    Builder $classQuery
                ): Builder =>
                $classQuery
                    ->withoutGlobalScopes()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
            );
    }

    public static function canViewAny(): bool
    {
        return static::actorCanAccessResource();
    }

    public static function canView(
        Model $record
    ): bool {
        if (! $record instanceof Enrollment) {
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
        /*
         * Enrollment creation will be added as a controlled
         * Filament Action that calls EnrollmentManagementService.
         *
         * Native Filament CRUD creation must remain disabled.
         */
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
            ListEnrollments::route('/'),

            'view' =>
            ViewEnrollment::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function transferTargetClassOptions(
        Enrollment $enrollment
    ): array {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || ! static::canView(
                $enrollment
            )
        ) {
            return [];
        }

        $tenant =
            app(TenantContext::class);

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
            || $enrollment->center_id
            !== $centerId
        ) {
            return [];
        }

        $query =
            CourseClass::withoutGlobalScopes()
            ->with('branch')
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'id',
                '<>',
                $enrollment->class_id
            )
            ->whereIn(
                'class_status',
                [
                    CourseClassStatus::Planned
                        ->value,

                    CourseClassStatus::Active
                        ->value,
                ]
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! app(
                    BranchContext::class
                )->isCenterWide()
            ) {
                return [];
            }
        } elseif (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            $branchContext =
                app(BranchContext::class);

            $branchId =
                $branchContext->branchId();

            if (
                ! $branchContext
                    ->isBranchScoped()
                || $branchId === null
            ) {
                return [];
            }

            if (
                ! $actor
                    ->activeBranchManagerAssignment()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
                    ->exists()
            ) {
                return [];
            }

            $query->where(
                'branch_id',
                $branchId
            );
        } else {
            return [];
        }

        return $query
            ->orderBy('class_code')
            ->get()
            ->mapWithKeys(
                function (
                    CourseClass $courseClass
                ): array {
                    $branchName =
                        $courseClass
                        ->branch
                        ?->name
                        ?? 'Unknown Branch';

                    return [
                        $courseClass->id =>
                        $courseClass
                            ->class_code
                            . ' — '
                            . $courseClass
                            ->name
                            . ' — '
                            . $branchName,
                    ];
                }
            )
            ->all();
    }

    private static function resolveTransferTargetClass(
        Enrollment $enrollment,
        int $targetClassId,
        User $actor
    ): CourseClass {
        if (
            ! static::canView(
                $enrollment
            )
        ) {
            throw new AuthorizationException(
                'The Enrollment is outside the authorized operational scope.'
            );
        }

        $tenant =
            app(TenantContext::class);

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
            || $enrollment->center_id
            !== $centerId
        ) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        $query =
            CourseClass::withoutGlobalScopes()
            ->whereKey(
                $targetClassId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'id',
                '<>',
                $enrollment->class_id
            )
            ->whereIn(
                'class_status',
                [
                    CourseClassStatus::Planned
                        ->value,

                    CourseClassStatus::Active
                        ->value,
                ]
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! app(
                    BranchContext::class
                )->isCenterWide()
            ) {
                throw new AuthorizationException(
                    'Center Owner Enrollment operations require center-wide Branch context.'
                );
            }
        } elseif (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            $branchContext =
                app(BranchContext::class);

            $branchId =
                $branchContext->branchId();

            if (
                ! $branchContext
                    ->isBranchScoped()
                || $branchId === null
            ) {
                throw new AuthorizationException(
                    'Branch operational context has not been established.'
                );
            }

            if (
                ! $actor
                    ->activeBranchManagerAssignment()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
                    ->exists()
            ) {
                throw new AuthorizationException(
                    'The Branch Manager does not have an active assignment for the current Branch.'
                );
            }

            $query->where(
                'branch_id',
                $branchId
            );
        } else {
            throw new AuthorizationException(
                'The account cannot manage Enrollments.'
            );
        }

        return $query
            ->firstOrFail();
    }

    private static function actorCanAccessResource(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if (
            ! $user->hasPermission(
                SystemPermission::ManageEnrollments
            )
        ) {
            return false;
        }

        if (
            ! in_array(
                $user->systemRole(),
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
            ! $tenant->isCenterScoped()
            || $tenant->centerId()
            !== $user->center_id
        ) {
            return false;
        }

        if (
            $user->systemRole()
            === SystemRole::CenterOwner
        ) {
            return app(
                BranchContext::class
            )->isCenterWide();
        }

        $branchContext =
            app(BranchContext::class);

        if (! $branchContext->isBranchScoped()) {
            return false;
        }

        $branchId =
            $branchContext->branchId();

        if ($branchId === null) {
            return false;
        }

        /*
         * BranchContext comes from the authenticated operational
         * assignment, but the persisted assignment is checked
         * again here so Filament navigation fails closed.
         */
        return $user
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $user->center_id
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
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function statusLabel(
        mixed $state
    ): string {
        if (
            $state instanceof
            EnrollmentStatus
        ) {
            return $state->label();
        }

        $status =
            EnrollmentStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return $status?->label()
            ?? ucfirst(
                (string) (
                    $state->value
                    ?? $state
                )
            );
    }
}