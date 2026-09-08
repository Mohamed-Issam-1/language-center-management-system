<?php

namespace App\Filament\Resources\FinanceEmployees;

use App\Filament\Resources\FinanceEmployees\Pages\ListFinanceEmployees;
use App\Filament\Resources\FinanceEmployees\Pages\ViewFinanceEmployee;
use App\Models\FinanceEmployee;
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

class FinanceEmployeeResource extends Resource
{
    protected static ?string $model =
    FinanceEmployee::class;

    protected static ?string $navigationLabel =
    'Finance Employees';

    protected static ?string $modelLabel =
    'Finance Employee';

    protected static ?string $pluralModelLabel =
    'Finance Employees';

    protected static string | \UnitEnum | null $navigationGroup =
    'People';

    protected static ?int $navigationSort =
    40;

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
                            'user.activeFinanceEmployeeAssignment.branch.name'
                        )
                            ->label(
                                'Current Branch'
                            )
                            ->placeholder(
                                'No active assignment'
                            ),

                        TextEntry::make(
                            'user.activeFinanceEmployeeAssignment.started_at'
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
                        'Finance Employee'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'user.activeFinanceEmployeeAssignment.branch.name'
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
                        'editFinanceEmployeeIdentity'
                    )
                        ->label(
                            'Edit Identity'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            fn(
                                FinanceEmployee $record
                            ): bool =>
                            static::canEditSharedIdentity(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Edit Finance Employee Identity'
                        )
                        ->modalDescription(
                            'These fields belong to the shared Person identity. National ID, Center, account credentials, and Branch assignment are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Identity'
                        )
                        ->fillForm(
                            function (
                                FinanceEmployee $record
                            ): array {
                                $person =
                                    static::authorizedFinanceEmployeePerson(
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
                                FinanceEmployee $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Finance Employee identity could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $person =
                                    static::authorizedFinanceEmployeePerson(
                                        $record
                                    );

                                if ($person === null) {
                                    static::failure(
                                        'Finance Employee identity could not be updated.',
                                        'The Finance Employee Person identity could not be resolved.'
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
                                        'Finance Employee identity could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Finance Employee identity could not be updated.',
                                        'An unexpected error occurred while updating the Finance Employee identity.'
                                    );

                                    return;
                                }

                                $record->refresh();
                                $record->load(
                                    'person'
                                );

                                Notification::make()
                                    ->title(
                                        'Finance Employee identity updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'createFinanceEmployeeAccount'
                    )
                        ->label(
                            'Create Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                FinanceEmployee $record
                            ): bool =>
                            static::canManageFinanceEmployee(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                                && $record->user_id
                                === null
                                && static::matchingFinanceEmployeeAccount(
                                    $record
                                ) === null
                        )
                        ->modalHeading(
                            'Create Finance Employee Account'
                        )
                        ->modalDescription(
                            'LCMS will generate a login identifier and temporary password and link the new account to this Finance Employee. Branch assignment remains a separate administrative action.'
                        )
                        ->modalSubmitActionLabel(
                            'Create & Send Credentials'
                        )
                        ->fillForm(
                            fn(
                                FinanceEmployee $record
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
                                FinanceEmployee $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Finance Employee Account could not be created.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $result =
                                        app(
                                            StaffAccountProvisioningService::class
                                        )->provisionFinanceEmployee(
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
                                        'Finance Employee Account could not be created.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Finance Employee Account could not be created.',
                                        'An unexpected error occurred while creating the Finance Employee Account.'
                                    );

                                    return;
                                }

                                /*
             * Account creation and operational linkage
             * have already committed.
             *
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
                                            'Finance Employee Account created'
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
                                        'Finance Employee Account created'
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
                        'linkExistingFinanceEmployeeAccount'
                    )
                        ->label(
                            'Link Existing Account'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            function (
                                FinanceEmployee $record
                            ): bool {
                                if (
                                    ! static::canManageFinanceEmployee(
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
                                    static::matchingFinanceEmployeeAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Link Existing Finance Employee Account'
                        )
                        ->modalDescription(
                            'A Finance Employee User Account already exists for the same Person. LCMS will link that account instead of creating a duplicate. Branch assignment is not changed.'
                        )
                        ->modalSubmitActionLabel(
                            'Link Account'
                        )
                        ->action(
                            function (
                                FinanceEmployee $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Finance Employee Account could not be linked.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::matchingFinanceEmployeeAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Finance Employee Account could not be linked.',
                                        'No matching Finance Employee User Account exists for this Person.'
                                    );

                                    return;
                                }

                                if (
                                    $account->status
                                    !== AccountStatus::Active
                                ) {
                                    static::failure(
                                        'Finance Employee Account could not be linked.',
                                        'The existing Finance Employee User Account must be Active before it can be linked.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        StaffOperationalManagementService::class
                                    )->linkFinanceEmployeeAccount(
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
                                        'Finance Employee Account could not be linked.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Finance Employee Account could not be linked.',
                                        'An unexpected error occurred while linking the Finance Employee Account.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Finance Employee Account linked'
                                    )
                                    ->body(
                                        'No Branch assignment was changed.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateFinanceEmployeeAccount'
                    )
                        ->label(
                            'Deactivate Account'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            function (
                                FinanceEmployee $record
                            ): bool {
                                $account =
                                    static::linkedFinanceEmployeeAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Finance Employee Account'
                        )
                        ->modalDescription(
                            'Only sign-in access will be deactivated. The Finance Employee operational record and current Branch assignment remain unchanged.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Account'
                        )
                        ->action(
                            fn(
                                FinanceEmployee $record
                            ) =>
                            static::runFinanceEmployeeAccountLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateFinanceEmployeeAccount'
                    )
                        ->label(
                            'Activate Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            function (
                                FinanceEmployee $record
                            ): bool {
                                $account =
                                    static::linkedFinanceEmployeeAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Deactivated;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Finance Employee Account'
                        )
                        ->modalDescription(
                            'The User Account will return to Active status. This does not activate a deactivated Finance Employee operational record or recreate any Branch assignment.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Account'
                        )
                        ->action(
                            fn(
                                FinanceEmployee $record
                            ) =>
                            static::runFinanceEmployeeAccountLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'reissueFinanceEmployeeCredentials'
                    )
                        ->label(
                            'Reissue Credentials'
                        )
                        ->color(
                            'warning'
                        )
                        ->visible(
                            function (
                                FinanceEmployee $record
                            ): bool {
                                $account =
                                    static::linkedFinanceEmployeeAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active
                                    && static::isFinanceEmployeeOperationallyActive(
                                        $record
                                    );
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Reissue Finance Employee Credentials'
                        )
                        ->modalDescription(
                            'A new temporary password will be generated. The previous password becomes invalid immediately and the Finance Employee must change the temporary password after signing in.'
                        )
                        ->modalSubmitActionLabel(
                            'Reissue Credentials'
                        )
                        ->action(
                            function (
                                FinanceEmployee $record
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
                                    static::linkedFinanceEmployeeAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The linked Finance Employee User Account could not be resolved.'
                                    );

                                    return;
                                }

                                if (
                                    ! static::isFinanceEmployeeOperationallyActive(
                                        $record
                                    )
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'Credentials cannot be reissued while the Finance Employee operational record is deactivated.'
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
                        'deactivateFinanceEmployee'
                    )
                        ->label(
                            'Deactivate'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            fn(
                                FinanceEmployee $record
                            ): bool =>
                            static::canManageFinanceEmployee(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Finance Employee'
                        )
                        ->modalDescription(
                            'The operational record will be deactivated and any active Finance Employee Branch assignment will be ended while its history is preserved. The User Account itself remains unchanged.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Finance Employee'
                        )
                        ->action(
                            fn(
                                FinanceEmployee $record
                            ) =>
                            static::runFinanceEmployeeLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateFinanceEmployee'
                    )
                        ->label(
                            'Activate'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                FinanceEmployee $record
                            ): bool =>
                            static::canManageFinanceEmployee(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Deactivated
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Finance Employee'
                        )
                        ->modalDescription(
                            'The operational record will return to Active status. No Branch assignment will be recreated automatically.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Finance Employee'
                        )
                        ->action(
                            fn(
                                FinanceEmployee $record
                            ) =>
                            static::runFinanceEmployeeLifecycle(
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
            FinanceEmployee::withoutGlobalScopes()
            ->with([
                'person',
                'user.role',
                'user.activeFinanceEmployeeAssignment.branch',
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
            instanceof FinanceEmployee
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
            ListFinanceEmployees::route(
                '/'
            ),

            'view' =>
            ViewFinanceEmployee::route(
                '/{record}'
            ),
        ];
    }

    public static function linkedFinanceEmployeeAccount(
        FinanceEmployee $record
    ): ?User {
        if (
            ! static::canManageFinanceEmployee(
                $record
            )
        ) {
            return null;
        }

        $financeEmployee =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $financeEmployee === null
            || $financeEmployee->user_id
            === null
            || $financeEmployee->person_id
            === null
        ) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $financeEmployee->user_id
            )
            ->where(
                'center_id',
                $financeEmployee->center_id
            )
            ->where(
                'person_id',
                $financeEmployee->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::FinanceEmployee
                        ->value
                )
            )
            ->first();
    }

    private static function isFinanceEmployeeOperationallyActive(
        FinanceEmployee $record
    ): bool {
        if (
            ! static::canManageFinanceEmployee(
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

    public static function matchingFinanceEmployeeAccount(
        FinanceEmployee $record
    ): ?User {
        if (
            ! static::canManageFinanceEmployee(
                $record
            )
            || $record->user_id
            !== null
        ) {
            return null;
        }

        /*
     * Re-resolve through the authorized Resource query.
     */
        $financeEmployee =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $financeEmployee === null
            || $financeEmployee->person_id
            === null
        ) {
            return null;
        }

        /*
     * A deactivated matching account is returned too so
     * Create Account does not attempt to create a duplicate
     * role account for the same Person.
     */
        return User::withoutGlobalScopes()
            ->with('role')
            ->where(
                'center_id',
                $financeEmployee->center_id
            )
            ->where(
                'person_id',
                $financeEmployee->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::FinanceEmployee
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
                            'finance_employees'
                        )
                        ->whereColumn(
                            'finance_employees.user_id',
                            'users.id'
                        );
                }
            )
            ->orderBy('id')
            ->first();
    }

    private static function canManageFinanceEmployee(
        FinanceEmployee $record
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
        FinanceEmployee $record
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

    public static function authorizedFinanceEmployeePerson(
        FinanceEmployee $record
    ): ?Person {
        if (
            ! static::canEditSharedIdentity(
                $record
            )
        ) {
            return null;
        }

        /*
     * Re-resolve through the authorized Resource scope.
     * Never trust mutable in-memory Center / Person state.
     */
        $financeEmployee =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $financeEmployee === null
            || $financeEmployee->person_id
            === null
        ) {
            return null;
        }

        return Person::withoutGlobalScopes()
            ->whereKey(
                $financeEmployee->person_id
            )
            ->where(
                'center_id',
                $financeEmployee->center_id
            )
            ->first();
    }

    private static function runFinanceEmployeeAccountLifecycle(
        FinanceEmployee $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Finance Employee Account lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        $account =
            static::linkedFinanceEmployeeAccount(
                $record
            );

        if ($account === null) {
            static::failure(
                'Finance Employee Account lifecycle could not be changed.',
                'The linked Finance Employee User Account could not be resolved.'
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
                    'Unsupported Finance Employee Account lifecycle operation.'
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
                'Finance Employee Account lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Finance Employee Account lifecycle could not be changed.',
                'An unexpected error occurred while changing the Finance Employee Account lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Finance Employee Account activated'
                    : 'Finance Employee Account deactivated'
            )
            ->success()
            ->send();
    }

    private static function runFinanceEmployeeLifecycle(
        FinanceEmployee $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Finance Employee lifecycle could not be changed.',
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
                $service->activateFinanceEmployee(
                    $actor,
                    $record
                ),

                'deactivate' =>
                $service->deactivateFinanceEmployee(
                    $actor,
                    $record
                ),

                default =>
                throw new LogicException(
                    'Unsupported Finance Employee lifecycle operation.'
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
                'Finance Employee lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Finance Employee lifecycle could not be changed.',
                'An unexpected error occurred while changing the Finance Employee lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Finance Employee activated'
                    : 'Finance Employee deactivated'
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