<?php

namespace App\Filament\Resources\UserAccounts;

use App\Filament\Resources\UserAccounts\Pages\ListUserAccounts;
use App\Filament\Resources\UserAccounts\Pages\ViewUserAccount;
use App\Models\BranchManagerAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
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
use App\Models\BranchManager;
use App\Models\FinanceEmployee;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Registration\RegistrationCredentialsReissueService;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\StudentStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use Filament\Actions\ActionGroup;

class UserAccountResource extends Resource
{
    protected static ?string $model =
    User::class;

    protected static ?string $navigationLabel =
    'User Accounts';

    protected static ?string $modelLabel =
    'User Account';

    protected static ?string $pluralModelLabel =
    'User Accounts';

    protected static string | \UnitEnum | null $navigationGroup =
    'People';

    protected static ?int $navigationSort =
    50;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Account Owner'
                )
                    ->schema([
                        TextEntry::make(
                            'person.full_name'
                        )
                            ->label(
                                'Full Name'
                            )
                            ->placeholder(
                                'No Person'
                            ),

                        TextEntry::make(
                            'person.national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            )
                            ->placeholder(
                                'No Person'
                            ),

                        TextEntry::make(
                            'role.name'
                        )
                            ->label(
                                'Role'
                            )
                            ->placeholder(
                                'Unknown'
                            ),

                        TextEntry::make(
                            'center.name'
                        )
                            ->label(
                                'Language Center'
                            )
                            ->placeholder(
                                'Platform Account'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Authentication'
                )
                    ->schema([
                        TextEntry::make(
                            'account_login_identifier'
                        )
                            ->label(
                                'Login Identifier'
                            ),

                        TextEntry::make(
                            'recovery_email'
                        )
                            ->label(
                                'Recovery Email'
                            ),

                        TextEntry::make(
                            'status'
                        )
                            ->label(
                                'Account Status'
                            )
                            ->badge()
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
                            'must_change_password'
                        )
                            ->label(
                                'Password Change Required'
                            )
                            ->formatStateUsing(
                                fn(
                                    bool $state
                                ): string =>
                                $state
                                    ? 'Yes'
                                    : 'No'
                            ),

                        TextEntry::make(
                            'failed_login_attempts'
                        )
                            ->label(
                                'Failed Login Attempts'
                            ),

                        TextEntry::make(
                            'locked_until'
                        )
                            ->label(
                                'Locked Until'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Not locked'
                            ),

                        TextEntry::make(
                            'last_login_at'
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

                        TextEntry::make(
                            'password_changed_at'
                        )
                            ->label(
                                'Password Changed At'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Never'
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
                    ->label(
                        'Account Owner'
                    )
                    ->searchable()
                    ->sortable()
                    ->placeholder(
                        'No Person'
                    ),

                TextColumn::make(
                    'account_login_identifier'
                )
                    ->label(
                        'Login Identifier'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'role.name'
                )
                    ->label(
                        'Role'
                    )
                    ->badge()
                    ->sortable(),

                TextColumn::make(
                    'center.name'
                )
                    ->label(
                        'Center'
                    )
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->visible(
                        function (): bool {
                            $actor =
                                auth()->user();

                            return $actor instanceof User
                                && $actor->systemRole()
                                === SystemRole::PlatformOwner;
                        }
                    ),

                TextColumn::make(
                    'recovery_email'
                )
                    ->label(
                        'Recovery Email'
                    )
                    ->searchable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
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

                TextColumn::make(
                    'failed_login_attempts'
                )
                    ->label(
                        'Failed Attempts'
                    ),

                TextColumn::make(
                    'locked_until'
                )
                    ->label(
                        'Locked Until'
                    )
                    ->dateTime(
                        'Y-m-d H:i'
                    )
                    ->placeholder(
                        '—'
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'role_id'
                )
                    ->label(
                        'Role'
                    )
                    ->options(
                        fn(): array =>
                        Role::query()
                            ->orderBy(
                                'name'
                            )
                            ->pluck(
                                'name',
                                'id'
                            )
                            ->all()
                    ),

                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Account Status'
                    )
                    ->options([
                        AccountStatus::Pending->value =>
                        'Pending',

                        AccountStatus::Active->value =>
                        'Active',

                        AccountStatus::Deactivated->value =>
                        'Deactivated',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'editUserAccount'
                    )
                        ->label(
                            'Edit Account'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            fn(
                                User $record
                            ): bool =>
                            static::canManageAccount(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Edit User Account'
                        )
                        ->modalDescription(
                            'Only the login identifier and recovery email can be changed here. Center, Person, Role, password, and lifecycle state are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Account'
                        )
                        ->fillForm(
                            function (
                                User $record
                            ): array {
                                $account =
                                    static::authorizedAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    return [];
                                }

                                return [
                                    'account_login_identifier' =>
                                    $account
                                        ->account_login_identifier,

                                    'recovery_email' =>
                                    $account
                                        ->recovery_email,
                                ];
                            }
                        )
                        ->schema([
                            TextInput::make(
                                'account_login_identifier'
                            )
                                ->label(
                                    'Login Identifier'
                                )
                                ->required()
                                ->maxLength(255),

                            TextInput::make(
                                'recovery_email'
                            )
                                ->label(
                                    'Recovery Email'
                                )
                                ->email()
                                ->required()
                                ->maxLength(255),
                        ])
                        ->action(
                            function (
                                User $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'User Account could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::authorizedAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'User Account could not be updated.',
                                        'The target User Account is outside the authorized scope.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        UserAccountManagementService::class
                                    )->update(
                                        $actor,
                                        $account,
                                        [
                                            'account_login_identifier' =>
                                            (string)
                                            $data['account_login_identifier'],

                                            'recovery_email' =>
                                            (string)
                                            $data['recovery_email'],
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
                                        'User Account could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'User Account could not be updated.',
                                        'An unexpected error occurred while updating the User Account.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'User Account updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateUserAccount'
                    )
                        ->label(
                            'Deactivate Account'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            function (
                                User $record
                            ): bool {
                                $account =
                                    static::authorizedAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate User Account'
                        )
                        ->modalDescription(
                            'Sign-in access will be deactivated. The Person and any Student or Staff operational record remain unchanged.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Account'
                        )
                        ->action(
                            fn(
                                User $record
                            ) =>
                            static::runAccountLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateUserAccount'
                    )
                        ->label(
                            'Activate Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            function (
                                User $record
                            ): bool {
                                $account =
                                    static::authorizedAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Deactivated;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate User Account'
                        )
                        ->modalDescription(
                            'The User Account will return to Active status. This does not restore or activate any Student or Staff operational record.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Account'
                        )
                        ->action(
                            fn(
                                User $record
                            ) =>
                            static::runAccountLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'reissueUserCredentials'
                    )
                        ->label(
                            'Reissue Credentials'
                        )
                        ->color(
                            'warning'
                        )
                        ->visible(
                            fn(
                                User $record
                            ): bool =>
                            static::canReissueCredentials(
                                $record
                            )
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Reissue User Credentials'
                        )
                        ->modalDescription(
                            'A new temporary password will be generated and sent to the recovery email. The previous password becomes invalid immediately.'
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
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::authorizedAccount(
                                        $record
                                    );

                                if (
                                    $account === null
                                    || ! static::canReissueCredentials(
                                        $account
                                    )
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The User Account is not eligible for credential reissue.'
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

                                    /*
                     * Password issuance commits before email
                     * delivery. If the hash changed, the new
                     * password is already authoritative even
                     * though delivery failed.
                     */
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
            User::withoutGlobalScopes()
            ->with([
                'person',
                'center',
                'role',
            ]);

        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return static::denyQuery(
                $query
            );
        }

        return match ($scope['role']) {
            SystemRole::PlatformOwner =>
            $query
                ->whereHas(
                    'role',
                    fn(
                        Builder $roleQuery
                    ): Builder =>
                    $roleQuery->where(
                        'code',
                        SystemRole::CenterOwner
                            ->value
                    )
                )
                ->whereNotNull(
                    'center_id'
                )
                ->whereNotNull(
                    'person_id'
                ),

            SystemRole::CenterOwner =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->whereHas(
                    'role',
                    fn(
                        Builder $roleQuery
                    ): Builder =>
                    $roleQuery->whereIn(
                        'code',
                        [
                            SystemRole::Student
                                ->value,

                            SystemRole::Teacher
                                ->value,

                            SystemRole::BranchManager
                                ->value,

                            SystemRole::FinanceEmployee
                                ->value,
                        ]
                    )
                ),

            SystemRole::BranchManager =>
            $query
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->whereHas(
                    'role',
                    fn(
                        Builder $roleQuery
                    ): Builder =>
                    $roleQuery->where(
                        'code',
                        SystemRole::Student
                            ->value
                    )
                )
                ->whereExists(
                    function (
                        $studentQuery
                    ) use (
                        $scope
                    ): void {
                        $studentQuery
                            ->selectRaw(
                                '1'
                            )
                            ->from(
                                'students'
                            )
                            ->whereColumn(
                                'students.user_id',
                                'users.id'
                            )
                            ->whereColumn(
                                'students.person_id',
                                'users.person_id'
                            )
                            ->where(
                                'students.center_id',
                                $scope['center_id']
                            )
                            ->where(
                                'students.branch_id',
                                $scope['branch_id']
                            );
                    }
                ),

            default =>
            static::denyQuery(
                $query
            ),
        };
    }

    public static function canViewAny(): bool
    {
        return static::authorizedScope()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        return $record
            instanceof User
            && static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->exists();
    }

    public static function canCreate(): bool
    {
        /*
         * Account creation belongs to the corresponding
         * operational workflow:
         *
         * Center Owners, Students, Teachers,
         * Branch Managers, and Finance Employees.
         *
         * This Resource must never create detached
         * role accounts.
         */
        return false;
    }

    public static function canEdit(
        Model $record
    ): bool {
        /*
         * Native Filament CRUD is disabled.
         *
         * UI-7D2 will use explicit actions backed by
         * UserAccountManagementService instead.
         */
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
            ListUserAccounts::route(
                '/'
            ),

            'view' =>
            ViewUserAccount::route(
                '/{record}'
            ),
        ];
    }

    private static function canManageAccount(
        User $record
    ): bool {
        return static::authorizedAccount(
            $record
        ) !== null;
    }

    private static function authorizedAccount(
        User $record
    ): ?User {
        if (
            $record->getKey()
            === null
        ) {
            return null;
        }

        /*
     * Always re-resolve through this Resource's
     * authoritative scoped query.
     *
     * This prevents an in-memory User instance from
     * bypassing Platform / Center / Branch scope.
     */
        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();
    }

    private static function canReissueCredentials(
        User $record
    ): bool {
        $account =
            static::authorizedAccount(
                $record
            );

        if (
            $account === null
            || $account->status
            !== AccountStatus::Active
        ) {
            return false;
        }

        $role =
            $account->systemRole();

        return match ($role) {
            /*
         * Center Owner has no separate operational
         * record. Platform scope already guarantees
         * that only Platform Owner can reach it here.
         */
            SystemRole::CenterOwner =>
            true,

            SystemRole::Student =>
            static::studentOperationalRecordIsActive(
                $account
            ),

            SystemRole::Teacher =>
            static::teacherOperationalRecordIsActive(
                $account
            ),

            SystemRole::BranchManager =>
            static::branchManagerOperationalRecordIsActive(
                $account
            ),

            SystemRole::FinanceEmployee =>
            static::financeEmployeeOperationalRecordIsActive(
                $account
            ),

            default =>
            false,
        };
    }

    private static function studentOperationalRecordIsActive(
        User $account
    ): bool {
        if (
            $account->center_id
            === null
            || $account->person_id
            === null
        ) {
            return false;
        }

        return Student::withoutGlobalScopes()
            ->where(
                'center_id',
                $account->center_id
            )
            ->where(
                'person_id',
                $account->person_id
            )
            ->where(
                'user_id',
                $account->id
            )
            ->where(
                'status',
                StudentStatus::Active
                    ->value
            )
            ->exists();
    }

    private static function teacherOperationalRecordIsActive(
        User $account
    ): bool {
        if (
            $account->center_id
            === null
            || $account->person_id
            === null
        ) {
            return false;
        }

        return Teacher::withoutGlobalScopes()
            ->where(
                'center_id',
                $account->center_id
            )
            ->where(
                'person_id',
                $account->person_id
            )
            ->where(
                'user_id',
                $account->id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->exists();
    }

    private static function branchManagerOperationalRecordIsActive(
        User $account
    ): bool {
        if (
            $account->center_id
            === null
            || $account->person_id
            === null
        ) {
            return false;
        }

        return BranchManager::withoutGlobalScopes()
            ->where(
                'center_id',
                $account->center_id
            )
            ->where(
                'person_id',
                $account->person_id
            )
            ->where(
                'user_id',
                $account->id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->exists();
    }

    private static function financeEmployeeOperationalRecordIsActive(
        User $account
    ): bool {
        if (
            $account->center_id
            === null
            || $account->person_id
            === null
        ) {
            return false;
        }

        return FinanceEmployee::withoutGlobalScopes()
            ->where(
                'center_id',
                $account->center_id
            )
            ->where(
                'person_id',
                $account->person_id
            )
            ->where(
                'user_id',
                $account->id
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->exists();
    }

    private static function runAccountLifecycle(
        User $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'User Account lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        $account =
            static::authorizedAccount(
                $record
            );

        if ($account === null) {
            static::failure(
                'User Account lifecycle could not be changed.',
                'The target User Account is outside the authorized scope.'
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
                    'Unsupported User Account lifecycle operation.'
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
                'User Account lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'User Account lifecycle could not be changed.',
                'An unexpected error occurred while changing the User Account lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'User Account activated'
                    : 'User Account deactivated'
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

    /**
     * @return array{
     *     role:SystemRole,
     *     actor_id:int,
     *     center_id:?int,
     *     branch_id:?int
     * }|null
     */
    private static function authorizedScope(): ?array
    {
        $actor =
            static::persistedActor();

        if ($actor === null) {
            return null;
        }

        $role =
            $actor->systemRole();

        $tenant =
            app(
                TenantContext::class
            );

        $branchContext =
            app(
                BranchContext::class
            );

        if (
            $role
            === SystemRole::PlatformOwner
        ) {
            if (
                $actor->center_id
                !== null
                || $actor->person_id
                !== null
                || ! $actor->hasPermission(
                    SystemPermission
                    ::ManageCenterOwnerAccounts
                )
                || ! $tenant->isEstablished()
                || ! $tenant->isPlatformScoped()
            ) {
                return null;
            }

            return [
                'role' =>
                $role,

                'actor_id' =>
                $actor->id,

                'center_id' =>
                null,

                'branch_id' =>
                null,
            ];
        }

        if (
            $role
            === SystemRole::CenterOwner
        ) {
            if (
                $actor->center_id
                === null
                || ! $actor->hasPermission(
                    SystemPermission
                    ::ManageStaffAccounts
                )
                || ! $actor->hasPermission(
                    SystemPermission
                    ::ManageStudentAccounts
                )
                || ! $tenant->isCenterScoped()
                || $tenant->centerId()
                !== $actor->center_id
                || ! $branchContext
                    ->isCenterWide()
            ) {
                return null;
            }

            return [
                'role' =>
                $role,

                'actor_id' =>
                $actor->id,

                'center_id' =>
                $actor->center_id,

                'branch_id' =>
                null,
            ];
        }

        if (
            $role
            === SystemRole::BranchManager
        ) {
            if (
                $actor->center_id
                === null
                || ! $actor->hasPermission(
                    SystemPermission
                    ::ManageStudentAccounts
                )
                || ! $tenant->isCenterScoped()
                || $tenant->centerId()
                !== $actor->center_id
                || ! $branchContext
                    ->isBranchScoped()
                || $branchContext
                ->branchId()
                === null
            ) {
                return null;
            }

            $branchId =
                $branchContext
                ->branchId();

            $hasActiveAssignment =
                BranchManagerAssignment
                ::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $actor->center_id
                )
                ->where(
                    'user_id',
                    $actor->id
                )
                ->where(
                    'branch_id',
                    $branchId
                )
                ->where(
                    'active_marker',
                    1
                )
                ->whereNull(
                    'ended_at'
                )
                ->exists();

            if (
                ! $hasActiveAssignment
            ) {
                return null;
            }

            return [
                'role' =>
                $role,

                'actor_id' =>
                $actor->id,

                'center_id' =>
                $actor->center_id,

                'branch_id' =>
                $branchId,
            ];
        }

        /*
         * Finance Employee has Filament access for Finance
         * workflows, but no User Account management permission.
         *
         * Teacher and Student do not use this internal panel.
         */
        return null;
    }

    private static function persistedActor(): ?User
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->getKey()
            === null
        ) {
            return null;
        }

        $persisted =
            User::withoutGlobalScopes()
            ->with(
                'role'
            )
            ->whereKey(
                $actor->getKey()
            )
            ->first();

        if (
            $persisted === null
            || $persisted->status
            !== AccountStatus::Active
        ) {
            return null;
        }

        return $persisted;
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function accountStatusLabel(
        mixed $state
    ): string {
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