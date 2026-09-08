<?php

namespace App\Filament\Resources\CenterOwners;

use App\Filament\Resources\CenterOwners\Pages\ListCenterOwners;
use App\Filament\Resources\CenterOwners\Pages\ViewCenterOwner;
use App\Models\User;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Registration\RegistrationCredentialsReissueService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use App\Services\Accounts\PlatformCenterOwnerIdentityUpdateService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;

class CenterOwnerResource extends Resource
{
    protected static ?string $model =
    User::class;

    protected static ?string $navigationLabel =
    'Center Owners';

    protected static ?string $modelLabel =
    'Center Owner';

    protected static ?string $pluralModelLabel =
    'Center Owners';

    protected static string | \UnitEnum | null $navigationGroup =
    'Platform';

    protected static ?int $navigationSort =
    20;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Center Owner Identity'
                )
                    ->schema([
                        TextEntry::make(
                            'person.full_name'
                        )
                            ->label(
                                'Full Name'
                            ),

                        TextEntry::make(
                            'person.national_id_number'
                        )
                            ->label(
                                'National ID'
                            ),

                        TextEntry::make(
                            'person.date_of_birth'
                        )
                            ->label(
                                'Date of Birth'
                            )
                            ->date(),

                        TextEntry::make(
                            'person.city_of_residence'
                        )
                            ->label(
                                'City'
                            ),

                        TextEntry::make(
                            'person.email'
                        )
                            ->label(
                                'Personal Email'
                            ),

                        TextEntry::make(
                            'person.phone_number'
                        )
                            ->label(
                                'Phone'
                            ),
                    ])
                    ->columns(3),

                Section::make(
                    'Account Information'
                )
                    ->schema([
                        TextEntry::make(
                            'center.name'
                        )
                            ->label(
                                'Language Center'
                            ),

                        TextEntry::make(
                            'account_login_identifier'
                        )
                            ->label(
                                'Login Identifier'
                            )
                            ->copyable(),

                        TextEntry::make(
                            'recovery_email'
                        )
                            ->label(
                                'Recovery Email'
                            ),

                        TextEntry::make(
                            'status'
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
                            'must_change_password'
                        )
                            ->label(
                                'Password Change'
                            )
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                (bool) $state
                                    ? 'Required'
                                    : 'Completed'
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                (bool) $state
                                    ? 'warning'
                                    : 'success'
                            ),

                        TextEntry::make(
                            'last_login_at'
                        )
                            ->label(
                                'Last Login'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Never'
                            ),

                        TextEntry::make(
                            'password_changed_at'
                        )
                            ->label(
                                'Password Changed'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Not yet'
                            ),

                        TextEntry::make(
                            'deactivated_at'
                        )
                            ->label(
                                'Deactivated At'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Not deactivated'
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
                    'person.full_name'
                )
                    ->label('Owner')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'center.name'
                )
                    ->label('Center')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'account_login_identifier'
                )
                    ->label(
                        'Login ID'
                    )
                    ->searchable()
                    ->copyable(),

                TextColumn::make(
                    'recovery_email'
                )
                    ->label(
                        'Recovery Email'
                    )
                    ->searchable()
                    ->toggleable(),

                TextColumn::make(
                    'must_change_password'
                )
                    ->label(
                        'Password'
                    )
                    ->badge()
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        (bool) $state
                            ? 'Change Required'
                            : 'Ready'
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        (bool) $state
                            ? 'warning'
                            : 'success'
                    ),

                TextColumn::make(
                    'status'
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

                TextColumn::make(
                    'last_login_at'
                )
                    ->label(
                        'Last Login'
                    )
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])

            ->filters([
                SelectFilter::make(
                    'center_id'
                )
                    ->label('Center')
                    ->relationship(
                        'center',
                        'name'
                    ),

                SelectFilter::make(
                    'status'
                )
                    ->label('Status')
                    ->options([
                        AccountStatus::Active
                            ->value =>
                        'Active',

                        AccountStatus::Pending
                            ->value =>
                        'Pending',

                        AccountStatus::Deactivated
                            ->value =>
                        'Deactivated',
                    ]),
            ])

            ->recordActions([
                ViewAction::make(),

                Action::make(
                    'updateIdentity'
                )
                    ->label('Update')
                    ->color('gray')
                    ->visible(
                        fn(
                            User $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Center Owner'
                    )
                    ->modalDescription(
                        'Update the Center Owner identity and contact information. Center, National ID, role, Login ID, status, and credential lifecycle are managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->fillForm(
                        function (
                            User $record
                        ): array {
                            $record->loadMissing(
                                'person'
                            );

                            return [
                                'full_name' =>
                                $record
                                    ->person
                                    ?->full_name,

                                'date_of_birth' =>
                                $record
                                    ->person
                                    ?->date_of_birth
                                    ?->format(
                                        'Y-m-d'
                                    ),

                                'city_of_residence' =>
                                $record
                                    ->person
                                    ?->city_of_residence,

                                'email' =>
                                $record
                                    ->person
                                    ?->email,

                                'phone_number' =>
                                $record
                                    ->person
                                    ?->phone_number,

                                'recovery_email' =>
                                $record
                                    ->recovery_email,
                            ];
                        }
                    )
                    ->schema([
                        TextInput::make(
                            'full_name'
                        )
                            ->label('Full Name')
                            ->required()
                            ->maxLength(255),

                        DatePicker::make(
                            'date_of_birth'
                        )
                            ->label(
                                'Date of Birth'
                            )
                            ->required()
                            ->native(false)
                            ->maxDate(
                                now()
                                    ->toDateString()
                            ),

                        TextInput::make(
                            'city_of_residence'
                        )
                            ->label(
                                'City of Residence'
                            )
                            ->required()
                            ->maxLength(150),

                        TextInput::make('email')
                            ->label(
                                'Personal Email'
                            )
                            ->email()
                            ->required()
                            ->maxLength(255),

                        TextInput::make(
                            'phone_number'
                        )
                            ->label(
                                'Phone Number'
                            )
                            ->required()
                            ->maxLength(50),

                        TextInput::make(
                            'recovery_email'
                        )
                            ->label(
                                'Recovery Email'
                            )
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->helperText(
                                'Password recovery codes and account credentials are delivered to this address.'
                            ),
                    ])
                    ->action(
                        function (
                            User $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Center Owner could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    PlatformCenterOwnerIdentityUpdateService::class
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
                                    'Center Owner could not be updated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Center Owner could not be updated.',
                                    'An unexpected error occurred while updating the Center Owner.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center Owner updated'
                                )
                                ->body(
                                    'Identity and account contact information were updated successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'reissueCredentials'
                )
                    ->label(
                        'Reissue Credentials'
                    )
                    ->color('warning')
                    ->visible(
                        fn(
                            User $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            === AccountStatus::Active
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Reissue Sign-in Credentials'
                    )
                    ->modalDescription(
                        'A new temporary password will be generated. The previous password will immediately become invalid and the Center Owner will be required to change the new temporary password after signing in.'
                    )
                    ->modalSubmitActionLabel(
                        'Reissue Credentials'
                    )
                    ->action(
                        function (
                            User $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Credentials could not be reissued.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            $previousPasswordHash =
                                (string)
                                $record->password;

                            try {
                                app(
                                    RegistrationCredentialsReissueService::class
                                )->reissue(
                                    $actor,
                                    $record
                                );
                            } catch (
                                Throwable $exception
                            ) {
                                $record->refresh();

                                $passwordWasReissued =
                                    ! hash_equals(
                                        $previousPasswordHash,
                                        (string)
                                        $record->password
                                    );

                                if (
                                    $passwordWasReissued
                                ) {
                                    Notification::make()
                                        ->title(
                                            'New credentials issued'
                                        )
                                        ->body(
                                            'A new temporary password was generated successfully, but the credentials email could not be delivered. The previous password is no longer valid. You may retry Reissue Credentials.'
                                        )
                                        ->warning()
                                        ->persistent()
                                        ->send();

                                    return;
                                }

                                $message =
                                    $exception
                                    instanceof AuthorizationException
                                    || $exception
                                    instanceof DomainException
                                    || $exception
                                    instanceof InvalidArgumentException
                                    || $exception
                                    instanceof LogicException
                                    || $exception
                                    instanceof ModelNotFoundException
                                    ? $exception
                                    ->getMessage()
                                    : 'The credentials could not be reissued.';

                                static::failure(
                                    'Credentials could not be reissued.',
                                    $message
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Credentials reissued'
                                )
                                ->body(
                                    'A new temporary password was generated and the credentials were sent successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'deactivateAccount'
                )
                    ->label('Deactivate')
                    ->color('danger')
                    ->visible(
                        fn(
                            User $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            === AccountStatus::Active
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Deactivate Center Owner'
                    )
                    ->modalDescription(
                        'The account will no longer be able to sign in. The Person, Center relationship, account history, and audit records will be preserved.'
                    )
                    ->modalSubmitActionLabel(
                        'Deactivate Account'
                    )
                    ->action(
                        function (
                            User $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Account could not be deactivated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    UserAccountManagementService::class
                                )->deactivate(
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
                                    'Account could not be deactivated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Account could not be deactivated.',
                                    'An unexpected error occurred while deactivating the account.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center Owner deactivated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'activateAccount'
                )
                    ->label('Activate')
                    ->color('success')
                    ->visible(
                        fn(
                            User $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            === AccountStatus::Deactivated
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Activate Center Owner'
                    )
                    ->modalDescription(
                        'The account will return to Active status. Authentication still requires the linked Language Center itself to be Active.'
                    )
                    ->modalSubmitActionLabel(
                        'Activate Account'
                    )
                    ->action(
                        function (
                            User $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Account could not be activated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    UserAccountManagementService::class
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
                                    'Account could not be activated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Account could not be activated.',
                                    'An unexpected error occurred while activating the account.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Center Owner activated'
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
            User::withoutGlobalScopes()
            ->with([
                'person',
                'center',
                'role',
            ])
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::CenterOwner
                        ->value
                )
            );

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
            ! $record instanceof User
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
     * Creation, lifecycle, credential and future identity
     * mutations use LCMS domain services.
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
            ListCenterOwners::route('/'),

            'view' =>
            ViewCenterOwner::route(
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
            $actor->systemRole()
            !== SystemRole::PlatformOwner
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission
                ::ManageCenterOwnerAccounts
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
            app(
                TenantContext::class
            );

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
            $state instanceof AccountStatus
            ? $state
            : AccountStatus::tryFrom(
                (string) $state
            );

        return match ($status) {
            AccountStatus::Active =>
            'Active',

            AccountStatus::Pending =>
            'Pending',

            AccountStatus::Deactivated =>
            'Deactivated',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state instanceof AccountStatus
            ? $state
            : AccountStatus::tryFrom(
                (string) $state
            );

        return match ($status) {
            AccountStatus::Active =>
            'success',

            AccountStatus::Pending =>
            'warning',

            AccountStatus::Deactivated =>
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