<?php

namespace App\Filament\Resources\BranchManagers;

use App\Filament\Resources\BranchManagers\Pages\ListBranchManagers;
use App\Filament\Resources\BranchManagers\Pages\ViewBranchManager;
use App\Models\BranchManager;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StaffStatus;
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
use App\Models\Person;
use App\Services\Accounts\CenterPersonIdentityUpdateService;
use App\Services\Staff\StaffOperationalManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Services\Staff\StaffAccountProvisioningService;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Registration\RegistrationCredentialsReissueService;
use Filament\Actions\ActionGroup;

class BranchManagerResource extends Resource
{
    protected static ?string $model =
    BranchManager::class;

    protected static ?string $navigationLabel =
    'Branch Managers';

    protected static ?string $modelLabel =
    'Branch Manager';

    protected static ?string $pluralModelLabel =
    'Branch Managers';

    protected static string | \UnitEnum | null $navigationGroup =
    'People';

    protected static ?int $navigationSort =
    30;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Identity'
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
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'person.date_of_birth'
                        )
                            ->label(
                                'Date of Birth'
                            )
                            ->date(
                                'Y-m-d'
                            ),

                        TextEntry::make(
                            'person.city_of_residence'
                        )
                            ->label(
                                'City of Residence'
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
                                'Phone Number'
                            ),
                    ])
                    ->columns(3),

                Section::make(
                    'Operational Record'
                )
                    ->schema([
                        TextEntry::make(
                            'status'
                        )
                            ->label(
                                'Staff Status'
                            )
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::staffStatusLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::staffStatusColor(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'deactivated_at'
                        )
                            ->label(
                                'Deactivated At'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Active'
                            ),

                        TextEntry::make(
                            'user.activeBranchManagerAssignment.branch.name'
                        )
                            ->label(
                                'Current Branch'
                            )
                            ->placeholder(
                                'No active assignment'
                            ),

                        TextEntry::make(
                            'user.activeBranchManagerAssignment.started_at'
                        )
                            ->label(
                                'Assignment Started'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'No active assignment'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'User Account'
                )
                    ->schema([
                        TextEntry::make(
                            'user.account_login_identifier'
                        )
                            ->label(
                                'Login Identifier'
                            )
                            ->placeholder(
                                'No linked account'
                            ),

                        TextEntry::make(
                            'user.recovery_email'
                        )
                            ->label(
                                'Recovery Email'
                            )
                            ->placeholder(
                                'No linked account'
                            ),

                        TextEntry::make(
                            'user.status'
                        )
                            ->label(
                                'Account Status'
                            )
                            ->badge()
                            ->placeholder(
                                'No linked account'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::accountStatusLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::accountStatusColor(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'user.last_login_at'
                        )
                            ->label(
                                'Last Login'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Never'
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
                    'person.full_name'
                )
                    ->label(
                        'Branch Manager'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'user.activeBranchManagerAssignment.branch.name'
                )
                    ->label(
                        'Current Branch'
                    )
                    ->placeholder(
                        'No assignment'
                    ),

                TextColumn::make(
                    'user.account_login_identifier'
                )
                    ->label(
                        'Account'
                    )
                    ->placeholder(
                        'No account'
                    ),

                TextColumn::make(
                    'status'
                )
                    ->label(
                        'Staff Status'
                    )
                    ->badge()
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::staffStatusLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::staffStatusColor(
                            $state
                        )
                    ),

                TextColumn::make(
                    'user.status'
                )
                    ->label(
                        'Account Status'
                    )
                    ->badge()
                    ->placeholder(
                        'No account'
                    )
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::accountStatusLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::accountStatusColor(
                            $state
                        )
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Staff Status'
                    )
                    ->options([
                        StaffStatus::Active->value =>
                        'Active',

                        StaffStatus::Deactivated->value =>
                        'Deactivated',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'editBranchManagerIdentity'
                    )
                        ->label(
                            'Edit Identity'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            fn(
                                BranchManager $record
                            ): bool =>
                            static::canEditSharedIdentity(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Edit Branch Manager Identity'
                        )
                        ->modalDescription(
                            'These fields belong to the shared Person identity. National ID, Center, account credentials, and Branch assignment are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Identity'
                        )
                        ->fillForm(
                            function (
                                BranchManager $record
                            ): array {
                                $person =
                                    static::authorizedBranchManagerPerson(
                                        $record
                                    );

                                if ($person === null) {
                                    return [];
                                }

                                return [
                                    'full_name' =>
                                    $person->full_name,

                                    'date_of_birth' =>
                                    $person->date_of_birth
                                        ?->format(
                                            'Y-m-d'
                                        ),

                                    'city_of_residence' =>
                                    $person
                                        ->city_of_residence,

                                    'email' =>
                                    $person->email,

                                    'phone_number' =>
                                    $person
                                        ->phone_number,
                                ];
                            }
                        )
                        ->schema([
                            TextInput::make(
                                'full_name'
                            )
                                ->label(
                                    'Full Name'
                                )
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
                                ),

                            TextInput::make(
                                'city_of_residence'
                            )
                                ->label(
                                    'City of Residence'
                                )
                                ->required()
                                ->maxLength(150),

                            TextInput::make(
                                'email'
                            )
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
                        ])
                        ->action(
                            function (
                                BranchManager $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Branch Manager identity could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $person =
                                    static::authorizedBranchManagerPerson(
                                        $record
                                    );

                                if ($person === null) {
                                    static::failure(
                                        'Branch Manager identity could not be updated.',
                                        'The Branch Manager Person identity could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        CenterPersonIdentityUpdateService::class
                                    )->update(
                                        $actor,
                                        $person,
                                        [
                                            'full_name' =>
                                            (string)
                                            $data['full_name'],

                                            'date_of_birth' =>
                                            $data['date_of_birth'],

                                            'city_of_residence' =>
                                            (string)
                                            $data['city_of_residence'],

                                            'email' =>
                                            (string)
                                            $data['email'],

                                            'phone_number' =>
                                            (string)
                                            $data['phone_number'],
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
                                    static::failure(
                                        'Branch Manager identity could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Branch Manager identity could not be updated.',
                                        'An unexpected error occurred while updating the Branch Manager identity.'
                                    );

                                    return;
                                }

                                $record->refresh();
                                $record->load(
                                    'person'
                                );

                                Notification::make()
                                    ->title(
                                        'Branch Manager identity updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'createBranchManagerAccount'
                    )
                        ->label(
                            'Create Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                BranchManager $record
                            ): bool =>
                            static::canManageBranchManager(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                                && $record->user_id
                                === null
                                && static::matchingBranchManagerAccount(
                                    $record
                                ) === null
                        )
                        ->modalHeading(
                            'Create Branch Manager Account'
                        )
                        ->modalDescription(
                            'LCMS will generate a login identifier and temporary password and link the new account to this Branch Manager. Branch assignment remains a separate administrative action.'
                        )
                        ->modalSubmitActionLabel(
                            'Create & Send Credentials'
                        )
                        ->fillForm(
                            fn(
                                BranchManager $record
                            ): array => [
                                'recovery_email' =>
                                $record
                                    ->person
                                    ?->email,
                            ]
                        )
                        ->schema([
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
                                    'Initial sign-in credentials will be sent to this address.'
                                ),
                        ])
                        ->action(
                            function (
                                BranchManager $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Branch Manager Account could not be created.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $result =
                                        app(
                                            StaffAccountProvisioningService::class
                                        )->provisionBranchManager(
                                            $actor,
                                            $record,
                                            (string)
                                            $data['recovery_email']
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
                                        'Branch Manager Account could not be created.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Branch Manager Account could not be created.',
                                        'An unexpected error occurred while creating the Branch Manager Account.'
                                    );

                                    return;
                                }

                                /*
             * Database work has committed.
             * Email delivery happens afterwards.
             */
                                try {
                                    app(
                                        RegistrationCredentialsDeliveryService::class
                                    )->deliverAccountCredentials(
                                        $result['account'],
                                        $result['temporary_password']
                                    );
                                } catch (Throwable) {
                                    $record->refresh();

                                    Notification::make()
                                        ->title(
                                            'Branch Manager Account created'
                                        )
                                        ->body(
                                            'The account was created and linked successfully, but the credentials email could not be delivered. Credentials can be reissued later.'
                                        )
                                        ->warning()
                                        ->persistent()
                                        ->send();

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Branch Manager Account created'
                                    )
                                    ->body(
                                        'Login identifier: '
                                            . $result['account']
                                            ->account_login_identifier
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'linkExistingBranchManagerAccount'
                    )
                        ->label(
                            'Link Existing Account'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            function (
                                BranchManager $record
                            ): bool {
                                if (
                                    ! static::canManageBranchManager(
                                        $record
                                    )
                                    || $record->status
                                    !== StaffStatus::Active
                                    || $record->user_id
                                    !== null
                                ) {
                                    return false;
                                }

                                $account =
                                    static::matchingBranchManagerAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Link Existing Branch Manager Account'
                        )
                        ->modalDescription(
                            'A Branch Manager User Account already exists for the same Person. LCMS will link that account instead of creating a duplicate. Branch assignment is not changed.'
                        )
                        ->modalSubmitActionLabel(
                            'Link Account'
                        )
                        ->action(
                            function (
                                BranchManager $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Branch Manager Account could not be linked.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::matchingBranchManagerAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Branch Manager Account could not be linked.',
                                        'No matching Branch Manager User Account exists for this Person.'
                                    );

                                    return;
                                }

                                if (
                                    $account->status
                                    !== AccountStatus::Active
                                ) {
                                    static::failure(
                                        'Branch Manager Account could not be linked.',
                                        'The existing Branch Manager User Account must be Active before it can be linked.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        StaffOperationalManagementService::class
                                    )->linkBranchManagerAccount(
                                        $actor,
                                        $record,
                                        $account
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
                                        'Branch Manager Account could not be linked.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Branch Manager Account could not be linked.',
                                        'An unexpected error occurred while linking the Branch Manager Account.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Branch Manager Account linked'
                                    )
                                    ->body(
                                        'No Branch assignment was changed.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateBranchManagerAccount'
                    )
                        ->label(
                            'Deactivate Account'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            function (
                                BranchManager $record
                            ): bool {
                                $account =
                                    static::linkedBranchManagerAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Branch Manager Account'
                        )
                        ->modalDescription(
                            'Only sign-in access will be deactivated. The Branch Manager operational record and current Branch assignment remain unchanged.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Account'
                        )
                        ->action(
                            fn(
                                BranchManager $record
                            ) =>
                            static::runBranchManagerAccountLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateBranchManagerAccount'
                    )
                        ->label(
                            'Activate Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            function (
                                BranchManager $record
                            ): bool {
                                $account =
                                    static::linkedBranchManagerAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Deactivated;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Branch Manager Account'
                        )
                        ->modalDescription(
                            'The User Account will return to Active status. This does not activate a deactivated Branch Manager operational record or recreate any Branch assignment.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Account'
                        )
                        ->action(
                            fn(
                                BranchManager $record
                            ) =>
                            static::runBranchManagerAccountLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'reissueBranchManagerCredentials'
                    )
                        ->label(
                            'Reissue Credentials'
                        )
                        ->color(
                            'warning'
                        )
                        ->visible(
                            function (
                                BranchManager $record
                            ): bool {
                                $account =
                                    static::linkedBranchManagerAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active
                                    && static::isBranchManagerOperationallyActive(
                                        $record
                                    );
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Reissue Branch Manager Credentials'
                        )
                        ->modalDescription(
                            'A new temporary password will be generated. The previous password becomes invalid immediately and the Branch Manager must change the temporary password after signing in.'
                        )
                        ->modalSubmitActionLabel(
                            'Reissue Credentials'
                        )
                        ->action(
                            function (
                                BranchManager $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::linkedBranchManagerAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The linked Branch Manager User Account could not be resolved.'
                                    );

                                    return;
                                }

                                if (
                                    ! static::isBranchManagerOperationallyActive(
                                        $record
                                    )
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'Credentials cannot be reissued while the Branch Manager operational record is deactivated.'
                                    );

                                    return;
                                }

                                $previousPasswordHash =
                                    (string)
                                    $account->password;

                                try {
                                    app(
                                        RegistrationCredentialsReissueService::class
                                    )->reissue(
                                        $actor,
                                        $account
                                    );
                                } catch (
                                    Throwable $exception
                                ) {
                                    $account->refresh();

                                    $passwordWasReissued =
                                        ! hash_equals(
                                            $previousPasswordHash,
                                            (string)
                                            $account->password
                                        );

                                    if ($passwordWasReissued) {
                                        Notification::make()
                                            ->title(
                                                'New credentials issued'
                                            )
                                            ->body(
                                                'A new temporary password was generated successfully, but its email could not be delivered. The previous password is no longer valid. You may retry Reissue Credentials.'
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
                                        ? $exception->getMessage()
                                        : 'The credentials could not be reissued.';

                                    static::failure(
                                        'Credentials could not be reissued.',
                                        $message
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Credentials reissued'
                                    )
                                    ->body(
                                        'A new temporary password was generated and sent successfully.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateBranchManager'
                    )
                        ->label(
                            'Deactivate'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            fn(
                                BranchManager $record
                            ): bool =>
                            static::canManageBranchManager(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Branch Manager'
                        )
                        ->modalDescription(
                            'The operational record will be deactivated and any active Branch Manager assignment will be ended while its history is preserved. The User Account itself remains unchanged.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Branch Manager'
                        )
                        ->action(
                            fn(
                                BranchManager $record
                            ) =>
                            static::runBranchManagerLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateBranchManager'
                    )
                        ->label(
                            'Activate'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                BranchManager $record
                            ): bool =>
                            static::canManageBranchManager(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Deactivated
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Branch Manager'
                        )
                        ->modalDescription(
                            'The operational record will return to Active status. No Branch assignment will be recreated automatically.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Branch Manager'
                        )
                        ->action(
                            fn(
                                BranchManager $record
                            ) =>
                            static::runBranchManagerLifecycle(
                                $record,
                                'activate'
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
            BranchManager::withoutGlobalScopes()
            ->with([
                'person',
                'user.role',
                'user.activeBranchManagerAssignment.branch',
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
        return $record
            instanceof BranchManager
            && static::getEloquentQuery()
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

    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' =>
            ListBranchManagers::route(
                '/'
            ),

            'view' =>
            ViewBranchManager::route(
                '/{record}'
            ),
        ];
    }

    public static function linkedBranchManagerAccount(
        BranchManager $record
    ): ?User {
        if (
            ! static::canManageBranchManager(
                $record
            )
        ) {
            return null;
        }

        $manager =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $manager === null
            || $manager->user_id
            === null
            || $manager->person_id
            === null
        ) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $manager->user_id
            )
            ->where(
                'center_id',
                $manager->center_id
            )
            ->where(
                'person_id',
                $manager->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::BranchManager
                        ->value
                )
            )
            ->first();
    }

    private static function isBranchManagerOperationallyActive(
        BranchManager $record
    ): bool {
        if (
            ! static::canManageBranchManager(
                $record
            )
        ) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->exists();
    }

    public static function matchingBranchManagerAccount(
        BranchManager $record
    ): ?User {
        if (
            ! static::canManageBranchManager(
                $record
            )
            || $record->user_id
            !== null
        ) {
            return null;
        }

        /*
     * Resolve through the authorized Resource query
     * instead of trusting mutable in-memory scope data.
     */
        $manager =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $manager === null
            || $manager->person_id
            === null
        ) {
            return null;
        }

        /*
     * A deactivated matching account is intentionally
     * returned too.
     *
     * This prevents Create Account from trying to create
     * a duplicate role account. Link itself is available
     * only when that existing account is Active.
     */
        return User::withoutGlobalScopes()
            ->with('role')
            ->where(
                'center_id',
                $manager->center_id
            )
            ->where(
                'person_id',
                $manager->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::BranchManager
                        ->value
                )
            )
            ->whereNotExists(
                function (
                    $query
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'branch_managers'
                        )
                        ->whereColumn(
                            'branch_managers.user_id',
                            'users.id'
                        );
                }
            )
            ->orderBy('id')
            ->first();
    }

    private static function canManageBranchManager(
        BranchManager $record
    ): bool {
        $actor =
            auth()->user();

        return $actor
            instanceof User
            && $actor->systemRole()
            === SystemRole::CenterOwner
            && $actor->hasPermission(
                SystemPermission
                ::ManageStaffAccounts
            )
            && static::canView(
                $record
            );
    }

    private static function canEditSharedIdentity(
        BranchManager $record
    ): bool {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->systemRole()
            !== SystemRole::CenterOwner
            || ! $actor->hasPermission(
                SystemPermission
                ::ManageStaffAccounts
            )
            || ! $actor->hasPermission(
                SystemPermission
                ::ManageStudentRecords
            )
        ) {
            return false;
        }

        return static::canView(
            $record
        )
            && $record->person_id
            !== null;
    }

    public static function authorizedBranchManagerPerson(
        BranchManager $record
    ): ?Person {
        if (
            ! static::canEditSharedIdentity(
                $record
            )
        ) {
            return null;
        }

        $manager =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $manager === null
            || $manager->person_id
            === null
        ) {
            return null;
        }

        return Person::withoutGlobalScopes()
            ->whereKey(
                $manager->person_id
            )
            ->where(
                'center_id',
                $manager->center_id
            )
            ->first();
    }

    private static function runBranchManagerAccountLifecycle(
        BranchManager $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Branch Manager Account lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        $account =
            static::linkedBranchManagerAccount(
                $record
            );

        if ($account === null) {
            static::failure(
                'Branch Manager Account lifecycle could not be changed.',
                'The linked Branch Manager User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    UserAccountManagementService::class
                );

            match ($operation) {
                'activate' =>
                $service->activate(
                    $actor,
                    $account
                ),

                'deactivate' =>
                $service->deactivate(
                    $actor,
                    $account
                ),

                default =>
                throw new LogicException(
                    'Unsupported Branch Manager Account lifecycle operation.'
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
                'Branch Manager Account lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Branch Manager Account lifecycle could not be changed.',
                'An unexpected error occurred while changing the Branch Manager Account lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Branch Manager Account activated'
                    : 'Branch Manager Account deactivated'
            )
            ->success()
            ->send();
    }

    private static function runBranchManagerLifecycle(
        BranchManager $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Branch Manager lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    StaffOperationalManagementService::class
                );

            match ($operation) {
                'activate' =>
                $service->activateBranchManager(
                    $actor,
                    $record
                ),

                'deactivate' =>
                $service->deactivateBranchManager(
                    $actor,
                    $record
                ),

                default =>
                throw new LogicException(
                    'Unsupported Branch Manager lifecycle operation.'
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
                'Branch Manager lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Branch Manager lifecycle could not be changed.',
                'An unexpected error occurred while changing the Branch Manager lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Branch Manager activated'
                    : 'Branch Manager deactivated'
            )
            ->success()
            ->send();
    }

    private static function failure(
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

    private static function authorizedCenterId(): ?int
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id
            === null
            || $actor->systemRole()
            !== SystemRole::CenterOwner
            || ! $actor->hasPermission(
                SystemPermission
                ::ManageStaffAccounts
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

        return $actor->center_id;
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function staffStatusLabel(
        mixed $state
    ): string {
        $status =
            $state instanceof StaffStatus
            ? $state
            : StaffStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return $status?->label()
            ?? 'Unknown';
    }

    private static function staffStatusColor(
        mixed $state
    ): string {
        $status =
            $state instanceof StaffStatus
            ? $state
            : StaffStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            StaffStatus::Active =>
            'success',

            StaffStatus::Deactivated =>
            'danger',

            default =>
            'gray',
        };
    }

    private static function accountStatusLabel(
        mixed $state
    ): string {
        if ($state === null) {
            return 'No Account';
        }

        $status =
            $state instanceof AccountStatus
            ? $state
            : AccountStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return $status?->label()
            ?? 'Unknown';
    }

    private static function accountStatusColor(
        mixed $state
    ): string {
        if ($state === null) {
            return 'gray';
        }

        $status =
            $state instanceof AccountStatus
            ? $state
            : AccountStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
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
}