<?php

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\Pages\ViewBranch;
use App\Models\Branch;
use App\Models\User;
use App\Services\Branches\BranchManagementService;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use LogicException;
use Throwable;
use App\Filament\Resources\Branches\Support\BranchStaffAssignmentActions;
use Filament\Actions\ActionGroup;

class BranchResource extends Resource
{
    protected static ?string $model =
    Branch::class;

    protected static ?string $navigationLabel =
    'Branches';

    protected static ?string $modelLabel =
    'Branch';

    protected static ?string $pluralModelLabel =
    'Branches';

    protected static string | \UnitEnum | null $navigationGroup =
    'Organization';

    protected static ?int $navigationSort =
    10;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Branch Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Branch Name'),

                        TextEntry::make('code')
                            ->label('Branch Code'),

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

                        TextEntry::make('phone')
                            ->label('Phone')
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make('address')
                            ->label('Address')
                            ->placeholder(
                                'Not provided'
                            )
                            ->columnSpanFull(),

                        TextEntry::make(
                            'working_hours'
                        )
                            ->label(
                                'Working Hours'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::workingHoursSummary(
                                    $state
                                )
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(
                    'Operational Summary'
                )
                    ->schema([
                        TextEntry::make(
                            'activeBranchManagerAssignment.user.person.full_name'
                        )
                            ->label(
                                'Branch Manager'
                            )
                            ->placeholder(
                                'Not assigned'
                            ),

                        TextEntry::make(
                            'active_finance_employee_assignments_count'
                        )
                            ->label(
                                'Finance Employees'
                            ),

                        TextEntry::make(
                            'classrooms_count'
                        )
                            ->label(
                                'Classrooms'
                            ),

                        TextEntry::make(
                            'students_count'
                        )
                            ->label(
                                'Students'
                            ),

                        TextEntry::make(
                            'course_classes_count'
                        )
                            ->label(
                                'Course Classes'
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
                TextColumn::make('name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'activeBranchManagerAssignment.user.person.full_name'
                )
                    ->label(
                        'Branch Manager'
                    )
                    ->placeholder(
                        'Not assigned'
                    )
                    ->wrap(),

                TextColumn::make(
                    'classrooms_count'
                )
                    ->label(
                        'Classrooms'
                    )
                    ->sortable(),

                TextColumn::make(
                    'students_count'
                )
                    ->label(
                        'Students'
                    )
                    ->sortable(),

                TextColumn::make(
                    'active_finance_employee_assignments_count'
                )
                    ->label(
                        'Finance'
                    )
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

                TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('email')
                    ->label('Email')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])

            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        BranchStatus::Active
                            ->value =>
                        'Active',

                        BranchStatus::Deactivated
                            ->value =>
                        'Deactivated',
                    ]),
            ])

            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    ...BranchStaffAssignmentActions
                        ::managerActions(),

                    ...BranchStaffAssignmentActions
                        ::financeActions(),

                    Action::make('updateBranch')
                        ->label('Update')
                        ->color('gray')
                        ->visible(
                            fn(
                                Branch $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Update Branch'
                        )
                        ->modalDescription(
                            'Update Branch information and working hours. Center ownership and lifecycle status are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Changes'
                        )
                        ->fillForm(
                            fn(
                                Branch $record
                            ): array => [
                                'name' =>
                                $record->name,

                                'code' =>
                                $record->code,

                                'phone' =>
                                $record->phone,

                                'email' =>
                                $record->email,

                                'address' =>
                                $record->address,

                                'working_hours' =>
                                static::workingHoursFormRows(
                                    $record
                                        ->working_hours
                                ),
                            ]
                        )
                        ->schema(
                            static::branchFormFields(
                                false
                            )
                        )
                        ->action(
                            function (
                                Branch $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Branch could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $data['working_hours'] =
                                        static::workingHoursPayload(
                                            $data['working_hours'] ?? []
                                        );

                                    app(
                                        BranchManagementService::class
                                    )->update(
                                        $actor,
                                        $record,
                                        $data
                                    );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::failure(
                                        'Branch could not be updated.',
                                        $exception
                                            ->getMessage()
                                    );

                                    return;
                                } catch (
                                    Throwable) {
                                    static::failure(
                                        'Branch could not be updated.',
                                        'An unexpected error occurred while updating the Branch.'
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Branch updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'activateBranch'
                    )
                        ->label('Activate')
                        ->color('success')
                        ->visible(
                            fn(
                                Branch $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                ===
                                BranchStatus::Deactivated
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Branch'
                        )
                        ->modalDescription(
                            'The Branch will become available for new operational activity.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Branch'
                        )
                        ->action(
                            function (
                                Branch $record
                            ): void {
                                static::changeLifecycle(
                                    $record,
                                    true
                                );
                            }
                        ),

                    Action::make(
                        'deactivateBranch'
                    )
                        ->label('Deactivate')
                        ->color('danger')
                        ->visible(
                            fn(
                                Branch $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                ===
                                BranchStatus::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Branch'
                        )
                        ->modalDescription(
                            'Existing history will be preserved, but new operational activity that requires an Active Branch will be blocked.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Branch'
                        )
                        ->action(
                            function (
                                Branch $record
                            ): void {
                                static::changeLifecycle(
                                    $record,
                                    false
                                );
                            }
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
            Branch::withoutGlobalScopes()
            ->with([
                'activeBranchManagerAssignment.user.person',
            ])
            ->withCount([
                'classrooms',
                'students',
                'courseClasses',
                'activeFinanceEmployeeAssignments',
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
            ! $record instanceof Branch
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
     * Native CRUD remains disabled.
     *
     * Branch mutations go through BranchManagementService.
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
            ListBranches::route('/'),

            'view' =>
            ViewBranch::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function branchFormFields(
        bool $includeStatus
    ): array {
        $fields = [
            TextInput::make('name')
                ->label('Branch Name')
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label('Branch Code')
                ->required()
                ->maxLength(50),

            TextInput::make('phone')
                ->label('Phone')
                ->maxLength(50),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),

            Textarea::make('address')
                ->label('Address')
                ->rows(3)
                ->maxLength(255)
                ->columnSpanFull(),
        ];

        if ($includeStatus) {
            $fields[] =
                Select::make('status')
                ->label(
                    'Initial Status'
                )
                ->options([
                    BranchStatus::Active
                        ->value =>
                    'Active',

                    BranchStatus::Deactivated
                        ->value =>
                    'Deactivated',
                ])
                ->default(
                    BranchStatus::Active
                        ->value
                )
                ->required();
        }

        $fields[] =
            Repeater::make(
                'working_hours'
            )
            ->label(
                'Working Hours'
            )
            ->schema([
                Select::make('day')
                    ->label('Day')
                    ->options(
                        static::dayOptions()
                    )
                    ->required()
                    ->distinct(),

                TextInput::make(
                    'opens_at'
                )
                    ->label(
                        'Opens At'
                    )
                    ->type('time')
                    ->required(),

                TextInput::make(
                    'closes_at'
                )
                    ->label(
                        'Closes At'
                    )
                    ->type('time')
                    ->required(),
            ])
            ->columns(3)
            ->default([])
            ->reorderable(false)
            ->addActionLabel(
                'Add Working Day'
            )
            ->columnSpanFull();

        return $fields;
    }

    /**
     * @param mixed $workingHours
     *
     * @return array<int, array{
     *     day:string,
     *     opens_at:string,
     *     closes_at:string
     * }>
     */
    public static function workingHoursFormRows(
        mixed $workingHours
    ): array {
        if (! is_array($workingHours)) {
            return [];
        }

        $rows = [];

        foreach (
            static::dayOptions()
            as $day => $label
        ) {
            $hours =
                $workingHours[$day]
                ?? null;

            if (! is_array($hours)) {
                continue;
            }

            $rows[] = [
                'day' => $day,

                'opens_at' =>
                (string) (
                    $hours['opens_at']
                    ?? ''
                ),

                'closes_at' =>
                (string) (
                    $hours['closes_at']
                    ?? ''
                ),
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $rows
     *
     * @return array<string, array{
     *     opens_at:string,
     *     closes_at:string
     * }>
     */
    public static function workingHoursPayload(
        mixed $rows
    ): array {
        if (! is_array($rows)) {
            return [];
        }

        $payload = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $day =
                strtolower(
                    trim(
                        (string) (
                            $row['day']
                            ?? ''
                        )
                    )
                );

            $opensAt =
                trim(
                    (string) (
                        $row['opens_at']
                        ?? ''
                    )
                );

            $closesAt =
                trim(
                    (string) (
                        $row['closes_at']
                        ?? ''
                    )
                );

            if (
                ! array_key_exists(
                    $day,
                    static::dayOptions()
                )
            ) {
                throw new DomainException(
                    'A valid working day is required.'
                );
            }

            if (
                ! preg_match(
                    '/^\d{2}:\d{2}$/',
                    $opensAt
                )
                || ! preg_match(
                    '/^\d{2}:\d{2}$/',
                    $closesAt
                )
            ) {
                throw new DomainException(
                    'Working hours must use valid opening and closing times.'
                );
            }

            if ($closesAt <= $opensAt) {
                throw new DomainException(
                    'Branch closing time must be later than opening time.'
                );
            }

            if (
                array_key_exists(
                    $day,
                    $payload
                )
            ) {
                throw new DomainException(
                    'Each working day may appear only once.'
                );
            }

            $payload[$day] = [
                'opens_at' =>
                $opensAt,

                'closes_at' =>
                $closesAt,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    public static function dayOptions(): array
    {
        return [
            'sunday' => 'Sunday',
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
        ];
    }

    public static function workingHoursSummary(
        mixed $workingHours
    ): string {
        if (
            ! is_array($workingHours)
            || $workingHours === []
        ) {
            return 'Not configured';
        }

        $parts = [];

        foreach (
            static::dayOptions()
            as $day => $label
        ) {
            $hours =
                $workingHours[$day]
                ?? null;

            if (! is_array($hours)) {
                continue;
            }

            $opensAt =
                (string) (
                    $hours['opens_at']
                    ?? ''
                );

            $closesAt =
                (string) (
                    $hours['closes_at']
                    ?? ''
                );

            if (
                $opensAt === ''
                || $closesAt === ''
            ) {
                continue;
            }

            $parts[] =
                $label
                . ': '
                . $opensAt
                . ' - '
                . $closesAt;
        }

        return $parts === []
            ? 'Not configured'
            : implode(
                ' | ',
                $parts
            );
    }

    private static function authorizedCenterId(): ?int
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
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
                SystemPermission::ManageBranches
            )
        ) {
            return null;
        }

        if ($actor->center_id === null) {
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

        return $actor->center_id;
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
        $status =
            $state instanceof BranchStatus
            ? $state
            : BranchStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            BranchStatus::Active =>
            'Active',

            BranchStatus::Deactivated =>
            'Deactivated',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state instanceof BranchStatus
            ? $state
            : BranchStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            BranchStatus::Active =>
            'success',

            BranchStatus::Deactivated =>
            'danger',

            default =>
            'gray',
        };
    }

    private static function changeLifecycle(
        Branch $record,
        bool $activate
    ): void {
        $actor =
            auth()->user();

        if (! $actor instanceof User) {
            static::failure(
                'Branch lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    BranchManagementService::class
                );

            if ($activate) {
                $service->activate(
                    $actor,
                    $record
                );
            } else {
                $service->deactivate(
                    $actor,
                    $record
                );
            }
        } catch (
            AuthorizationException
            | DomainException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Branch lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Branch lifecycle could not be changed.',
                'An unexpected error occurred while changing the Branch lifecycle.'
            );

            return;
        }

        Notification::make()
            ->title(
                $activate
                    ? 'Branch activated'
                    : 'Branch deactivated'
            )
            ->success()
            ->send();
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