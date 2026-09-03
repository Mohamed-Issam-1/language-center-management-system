<?php

namespace App\Filament\Resources\AttendanceStatuses;

use App\Filament\Resources\AttendanceStatuses\Pages\ListAttendanceStatuses;
use App\Filament\Resources\AttendanceStatuses\Pages\ViewAttendanceStatus;
use App\Models\AttendanceStatus;
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
use App\Services\Attendance\AttendanceStatusManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class AttendanceStatusResource extends Resource
{
    protected static ?string $model =
    AttendanceStatus::class;

    protected static ?string $navigationLabel =
    'Attendance Statuses';

    protected static ?string $modelLabel =
    'Attendance Status';

    protected static ?string $pluralModelLabel =
    'Attendance Statuses';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Attendance Status'
                )
                    ->schema([
                        TextEntry::make(
                            'name'
                        )
                            ->label(
                                'Name'
                            ),

                        TextEntry::make(
                            'code'
                        )
                            ->label(
                                'Code'
                            ),

                        TextEntry::make(
                            'contribution_value'
                        )
                            ->label(
                                'Contribution Value'
                            )
                            ->suffix('%'),

                        TextEntry::make(
                            'is_active'
                        )
                            ->label(
                                'Lifecycle Status'
                            )
                            ->formatStateUsing(
                                fn(
                                    bool $state
                                ): string =>
                                $state
                                    ? 'Active'
                                    : 'Inactive'
                            )
                            ->badge(),
                    ])
                    ->columns(2),

                Section::make(
                    'System Information'
                )
                    ->schema([
                        TextEntry::make(
                            'created_at'
                        )
                            ->label(
                                'Created At'
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
                    'name'
                )
                    ->label(
                        'Name'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'code'
                )
                    ->label(
                        'Code'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'contribution_value'
                )
                    ->label(
                        'Contribution'
                    )
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make(
                    'is_active'
                )
                    ->label(
                        'Status'
                    )
                    ->formatStateUsing(
                        fn(
                            bool $state
                        ): string =>
                        $state
                            ? 'Active'
                            : 'Inactive'
                    )
                    ->badge(),

                TextColumn::make(
                    'updated_at'
                )
                    ->label(
                        'Last Updated'
                    )
                    ->dateTime(
                        'Y-m-d H:i'
                    )
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make(
                    'updateAttendanceStatus'
                )
                    ->label(
                        'Update'
                    )
                    ->color(
                        'warning'
                    )
                    ->visible(
                        fn(
                            AttendanceStatus $record
                        ): bool =>
                        static::actorCanUpdateStatus(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Attendance Status'
                    )
                    ->modalDescription(
                        'Update the Attendance Status configuration. Lifecycle state is managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->schema([
                        TextInput::make(
                            'name'
                        )
                            ->label(
                                'Name'
                            )
                            ->default(
                                fn(
                                    AttendanceStatus $record
                                ): string =>
                                $record->name
                            )
                            ->required()
                            ->maxLength(100),

                        TextInput::make(
                            'code'
                        )
                            ->label(
                                'Code'
                            )
                            ->default(
                                fn(
                                    AttendanceStatus $record
                                ): string =>
                                $record->code
                            )
                            ->required()
                            ->maxLength(50),

                        TextInput::make(
                            'contribution_value'
                        )
                            ->label(
                                'Contribution Value'
                            )
                            ->default(
                                fn(
                                    AttendanceStatus $record
                                ): string =>
                                $record
                                    ->contribution_value
                            )
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                    ])
                    ->action(
                        function (
                            AttendanceStatus $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor instanceof User
                            ) {
                                static::statusUpdateFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                /*
                     * Re-resolve through the current Resource
                     * scope to prevent cross-Center tampering.
                     */
                                $status =
                                    static::resolveScopedStatus(
                                        $record
                                    );

                                $status =
                                    app(
                                        AttendanceStatusManagementService::class
                                    )->update(
                                        $actor,
                                        $status,
                                        [
                                            'name' =>
                                            $data['name'],

                                            'code' =>
                                            $data['code'],

                                            'contribution_value' =>
                                            $data['contribution_value'],
                                        ]
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::statusUpdateFailure(
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Attendance Status updated'
                                )
                                ->body(
                                    'Attendance Status '
                                        . $status->name
                                        . ' ('
                                        . $status->code
                                        . ') was updated successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),
                Action::make(
                    'activateAttendanceStatus'
                )
                    ->label(
                        'Activate'
                    )
                    ->color(
                        'success'
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Activate Attendance Status'
                    )
                    ->modalDescription(
                        'This Attendance Status will become available for future attendance records.'
                    )
                    ->modalSubmitActionLabel(
                        'Activate'
                    )
                    ->visible(
                        fn(
                            AttendanceStatus $record
                        ): bool =>
                        static::actorCanChangeStatusLifecycle(
                            $record
                        )
                            && ! $record->is_active
                    )
                    ->action(
                        function (
                            AttendanceStatus $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor instanceof User
                            ) {
                                static::statusLifecycleFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                $status =
                                    static::resolveScopedStatus(
                                        $record
                                    );

                                $status =
                                    app(
                                        AttendanceStatusManagementService::class
                                    )->activate(
                                        $actor,
                                        $status
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::statusLifecycleFailure(
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Attendance Status activated'
                                )
                                ->body(
                                    $status->name
                                        . ' ('
                                        . $status->code
                                        . ') is now Active.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'deactivateAttendanceStatus'
                )
                    ->label(
                        'Deactivate'
                    )
                    ->color(
                        'danger'
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Deactivate Attendance Status'
                    )
                    ->modalDescription(
                        'The status will no longer be available for new attendance records. Historical attendance remains unchanged.'
                    )
                    ->modalSubmitActionLabel(
                        'Deactivate'
                    )
                    ->visible(
                        fn(
                            AttendanceStatus $record
                        ): bool =>
                        static::actorCanChangeStatusLifecycle(
                            $record
                        )
                            && $record->is_active
                    )
                    ->action(
                        function (
                            AttendanceStatus $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor instanceof User
                            ) {
                                static::statusLifecycleFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                $status =
                                    static::resolveScopedStatus(
                                        $record
                                    );

                                $status =
                                    app(
                                        AttendanceStatusManagementService::class
                                    )->deactivate(
                                        $actor,
                                        $status
                                    );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::statusLifecycleFailure(
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Attendance Status deactivated'
                                )
                                ->body(
                                    $status->name
                                        . ' ('
                                        . $status->code
                                        . ') is now Inactive.'
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
            AttendanceStatus::withoutGlobalScopes();

        if (
            ! static::actorCanAccessResource()
        ) {
            return static::denyQuery(
                $query
            );
        }

        $centerId =
            app(TenantContext::class)
            ->centerId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        return $query
            ->where(
                'center_id',
                $centerId
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
                instanceof AttendanceStatus
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
     * Creation and lifecycle changes are exposed through
     * explicit domain Actions only.
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
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' =>
            ListAttendanceStatuses::route(
                '/'
            ),

            'view' =>
            ViewAttendanceStatus::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanUpdateStatus(
        AttendanceStatus $record
    ): bool {
        return static::canView(
            $record
        );
    }

    private static function resolveScopedStatus(
        AttendanceStatus $record
    ): AttendanceStatus {
        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->firstOrFail();
    }

    private static function statusUpdateFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Attendance Status could not be updated'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }

    private static function actorCanChangeStatusLifecycle(
        AttendanceStatus $record
    ): bool {
        return static::canView(
            $record
        );
    }

    private static function statusLifecycleFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Attendance Status lifecycle could not be changed'
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
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageAttendanceSettings
            )
        ) {
            return false;
        }

        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant->isCenterScoped()
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

        return app(
            BranchContext::class
        )->isCenterWide();
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