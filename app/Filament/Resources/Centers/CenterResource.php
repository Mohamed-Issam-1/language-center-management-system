<?php

namespace App\Filament\Resources\Centers;

use App\Filament\Resources\Centers\Pages\ListCenters;
use App\Filament\Resources\Centers\Pages\ViewCenter;
use App\Models\Center;
use App\Models\User;
use App\Services\Centers\CenterManagementService;
use App\Support\Enums\CenterStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
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

class CenterResource extends Resource
{
    protected static ?string $model =
    Center::class;

    protected static ?string $navigationLabel =
    'Centers';

    protected static ?string $modelLabel =
    'Center';

    protected static ?string $pluralModelLabel =
    'Centers';

    protected static string | \UnitEnum | null $navigationGroup =
    'Platform';

    protected static ?int $navigationSort =
    10;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Center Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Center Name'),

                        TextEntry::make('code')
                            ->label('Center Code'),

                        TextEntry::make(
                            'identifier_code'
                        )
                            ->label(
                                'Identifier Code'
                            ),

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

                        TextEntry::make('timezone')
                            ->label('Timezone'),

                        TextEntry::make(
                            'operating_currency_code'
                        )
                            ->label(
                                'Operating Currency'
                            ),
                    ])
                    ->columns(3),

                Section::make(
                    'Contact Information'
                )
                    ->schema([
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make('phone')
                            ->label('Phone')
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make('address')
                            ->label('Address')
                            ->placeholder(
                                'Not provided'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(
                    'Operational Summary'
                )
                    ->schema([
                        TextEntry::make(
                            'branches_count'
                        )
                            ->label('Branches'),

                        TextEntry::make(
                            'users_count'
                        )
                            ->label('User Accounts'),

                        TextEntry::make(
                            'students_count'
                        )
                            ->label('Students'),
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
                    ->label('Center')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'identifier_code'
                )
                    ->label('Identifier')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'branches_count'
                )
                    ->label('Branches')
                    ->sortable(),

                TextColumn::make(
                    'users_count'
                )
                    ->label('Accounts')
                    ->sortable(),

                TextColumn::make(
                    'students_count'
                )
                    ->label('Students')
                    ->sortable(),

                TextColumn::make(
                    'operating_currency_code'
                )
                    ->label('Currency')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('timezone')
                    ->label('Timezone')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

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
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        CenterStatus::Active
                            ->value =>
                        'Active',

                        CenterStatus::Suspended
                            ->value =>
                        'Suspended',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('updateCenter')
                    ->label('Update')
                    ->color('gray')
                    ->visible(
                        fn(
                            Center $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Center'
                    )
                    ->modalDescription(
                        'Update the Center information. Identifier Code and lifecycle status are managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->fillForm(
                        fn(
                            Center $record
                        ): array => [
                            'code' =>
                            $record->code,

                            'name' =>
                            $record->name,

                            'email' =>
                            $record->email,

                            'phone' =>
                            $record->phone,

                            'address' =>
                            $record->address,

                            'timezone' =>
                            $record->timezone,

                            'operating_currency_code' =>
                            $record
                                ->operating_currency_code,
                        ]
                    )
                    ->schema(
                        static::centerUpdateFields()
                    )
                    ->action(
                        function (
                            Center $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Center could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    CenterManagementService::class
                                )->update(
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
                                    'Center could not be updated.',
                                    $exception->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Center could not be updated.',
                                    'An unexpected error occurred while updating the Center.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center updated'
                                )
                                ->body(
                                    'The Center information was updated successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('activateCenter')
                    ->label('Activate')
                    ->color('success')
                    ->visible(
                        fn(
                            Center $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            === CenterStatus::Suspended
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Activate Center'
                    )
                    ->modalDescription(
                        'Activating this Center allows its active accounts to authenticate and resume normal Center operations.'
                    )
                    ->modalSubmitActionLabel(
                        'Activate Center'
                    )
                    ->action(
                        function (
                            Center $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Center could not be activated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    CenterManagementService::class
                                )->activate(
                                    $actor,
                                    $record
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
                                    'Center could not be activated.',
                                    $exception->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Center could not be activated.',
                                    'An unexpected error occurred while activating the Center.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center activated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('suspendCenter')
                    ->label('Suspend')
                    ->color('danger')
                    ->visible(
                        fn(
                            Center $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            === CenterStatus::Active
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Suspend Center'
                    )
                    ->modalDescription(
                        'Suspending a Center blocks authentication for Center accounts while preserving all Center data and history.'
                    )
                    ->modalSubmitActionLabel(
                        'Suspend Center'
                    )
                    ->action(
                        function (
                            Center $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Center could not be suspended.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    CenterManagementService::class
                                )->suspend(
                                    $actor,
                                    $record
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
                                    'Center could not be suspended.',
                                    $exception->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Center could not be suspended.',
                                    'An unexpected error occurred while suspending the Center.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center suspended'
                                )
                                ->success()
                                ->send();
                        }
                    ),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Center::query()
            ->withCount([
                'branches',
                'users',
                'students',
            ]);

        if (
            ! static::actorCanAccessResource()
        ) {
            return static::denyQuery(
                $query
            );
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::actorCanAccessResource();
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record instanceof Center
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
     * Native CRUD is disabled deliberately.
     *
     * All business mutations must go through
     * CenterManagementService.
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
            ListCenters::route('/'),

            'view' =>
            ViewCenter::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function centerUpdateFields(): array
    {
        return [
            TextInput::make('name')
                ->label('Center Name')
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label('Center Code')
                ->required()
                ->maxLength(50),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),

            TextInput::make('phone')
                ->label('Phone')
                ->maxLength(50),

            TextInput::make('timezone')
                ->label('Timezone')
                ->required()
                ->maxLength(100)
                ->helperText(
                    'Example: Asia/Gaza'
                ),

            TextInput::make(
                'operating_currency_code'
            )
                ->label(
                    'Operating Currency'
                )
                ->required()
                ->length(3)
                ->helperText(
                    'Three-letter currency code, for example USD.'
                ),

            Textarea::make('address')
                ->label('Address')
                ->rows(3)
                ->maxLength(255)
                ->columnSpanFull(),
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
            $actor->systemRole()
            !== SystemRole::PlatformOwner
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageCenters
            )
        ) {
            return false;
        }

        if (
            $actor->center_id !== null
            || $actor->person_id !== null
        ) {
            return false;
        }

        $tenant =
            app(TenantContext::class);

        return $tenant->isEstablished()
            && $tenant->isPlatformScoped();
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
            $state instanceof CenterStatus
            ? $state
            : CenterStatus::tryFrom(
                (string) $state
            );

        return match ($status) {
            CenterStatus::Active =>
            'Active',

            CenterStatus::Suspended =>
            'Suspended',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state instanceof CenterStatus
            ? $state
            : CenterStatus::tryFrom(
                (string) $state
            );

        return match ($status) {
            CenterStatus::Active =>
            'success',

            CenterStatus::Suspended =>
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