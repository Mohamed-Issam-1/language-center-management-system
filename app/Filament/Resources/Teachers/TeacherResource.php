<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Filament\Resources\Teachers\Pages\ViewTeacher;
use App\Models\Teacher;
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
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use DomainException;
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

class TeacherResource extends Resource
{
    protected static ?string $model =
    Teacher::class;

    protected static ?string $navigationLabel =
    'Teachers';

    protected static ?string $modelLabel =
    'Teacher';

    protected static ?string $pluralModelLabel =
    'Teachers';

    protected static string | \UnitEnum | null $navigationGroup =
    'People';

    protected static ?int $navigationSort =
    20;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Teacher Identity'
                )
                    ->schema([
                        TextEntry::make(
                            'person.full_name'
                        )
                            ->label(
                                'Full Name'
                            )
                            ->placeholder(
                                'Not provided'
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
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'person.city_of_residence'
                        )
                            ->label(
                                'City of Residence'
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'person.email'
                        )
                            ->label(
                                'Personal Email'
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'person.phone_number'
                        )
                            ->label(
                                'Phone Number'
                            )
                            ->placeholder(
                                'Not provided'
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
                                'Teacher Status'
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
                            'assigned_course_classes_count'
                        )
                            ->label(
                                'Assigned Classes'
                            ),

                        TextEntry::make(
                            'class_schedules_count'
                        )
                            ->label(
                                'Schedules'
                            ),

                        TextEntry::make(
                            'class_sessions_count'
                        )
                            ->label(
                                'Sessions'
                            ),
                    ])
                    ->columns(3),

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
                            'user.must_change_password'
                        )
                            ->label(
                                'Password Change Required'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                $state
                                    ? 'Yes'
                                    : 'No'
                            )
                            ->placeholder(
                                'No linked account'
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
                        'Teacher'
                    )
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->placeholder(
                        'Unnamed Person'
                    ),

                TextColumn::make(
                    'person.national_id_number'
                )
                    ->label(
                        'National ID'
                    )
                    ->searchable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'user.account_login_identifier'
                )
                    ->label(
                        'Account'
                    )
                    ->searchable()
                    ->placeholder(
                        'No account'
                    ),

                TextColumn::make(
                    'status'
                )
                    ->label(
                        'Teacher Status'
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

                TextColumn::make(
                    'assigned_course_classes_count'
                )
                    ->label(
                        'Classes'
                    )
                    ->sortable(),

                TextColumn::make(
                    'class_schedules_count'
                )
                    ->label(
                        'Schedules'
                    )
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Teacher Status'
                    )
                    ->options([
                        StaffStatus::Active
                            ->value =>
                        'Active',

                        StaffStatus::Deactivated
                            ->value =>
                        'Deactivated',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'editTeacherIdentity'
                    )
                        ->label(
                            'Edit Identity'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            fn(
                                Teacher $record
                            ): bool =>
                            static::canEditSharedIdentity(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Edit Teacher Identity'
                        )
                        ->modalDescription(
                            'These fields belong to the shared Person identity. National ID, Center, account credentials, and operational status are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Identity'
                        )
                        ->fillForm(
                            function (
                                Teacher $record
                            ): array {
                                $person =
                                    static::authorizedTeacherPerson(
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
                                Teacher $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Teacher identity could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $person =
                                    static::authorizedTeacherPerson(
                                        $record
                                    );

                                if ($person === null) {
                                    static::failure(
                                        'Teacher identity could not be updated.',
                                        'The Teacher Person identity could not be resolved.'
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
                                        'Teacher identity could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Teacher identity could not be updated.',
                                        'An unexpected error occurred while updating the Teacher identity.'
                                    );

                                    return;
                                }

                                $record->refresh();
                                $record->load(
                                    'person'
                                );

                                Notification::make()
                                    ->title(
                                        'Teacher identity updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'createTeacherAccount'
                    )
                        ->label(
                            'Create Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                Teacher $record
                            ): bool =>
                            static::canManageTeacher(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                                && $record->user_id
                                === null
                                && static::matchingTeacherAccount(
                                    $record
                                ) === null
                        )
                        ->modalHeading(
                            'Create Teacher Account'
                        )
                        ->modalDescription(
                            'LCMS will generate a login identifier and temporary password, link the account to this Teacher, and send the credentials by email.'
                        )
                        ->modalSubmitActionLabel(
                            'Create & Send Credentials'
                        )
                        ->fillForm(
                            fn(
                                Teacher $record
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
                                Teacher $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Teacher Account could not be created.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $result =
                                        app(
                                            StaffAccountProvisioningService::class
                                        )->provisionTeacher(
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
                                        'Teacher Account could not be created.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Teacher Account could not be created.',
                                        'An unexpected error occurred while creating the Teacher Account.'
                                    );

                                    return;
                                }

                                /*
             * Account creation and Teacher linkage have
             * already committed.
             *
             * Email delivery happens afterwards and must not
             * roll the database state back.
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
                                            'Teacher Account created'
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
                                        'Teacher Account created'
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
                        'deactivateTeacher'
                    )
                        ->label(
                            'Deactivate Teacher'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            fn(
                                Teacher $record
                            ): bool =>
                            static::canManageTeacher(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Teacher'
                        )
                        ->modalDescription(
                            'The Teacher operational record will be deactivated. The Person, historical classes, schedules, sessions, and linked User Account are preserved. The User Account itself is not deactivated by this action.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Teacher'
                        )
                        ->action(
                            fn(
                                Teacher $record
                            ) =>
                            static::runTeacherLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateTeacher'
                    )
                        ->label(
                            'Activate Teacher'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            fn(
                                Teacher $record
                            ): bool =>
                            static::canManageTeacher(
                                $record
                            )
                                && $record->status
                                === StaffStatus::Deactivated
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Teacher'
                        )
                        ->modalDescription(
                            'The Teacher operational record will return to Active status. If a User Account is linked, that account must already be Active.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Teacher'
                        )
                        ->action(
                            fn(
                                Teacher $record
                            ) =>
                            static::runTeacherLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'linkExistingTeacherAccount'
                    )
                        ->label(
                            'Link Existing Account'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            function (
                                Teacher $record
                            ): bool {
                                if (
                                    ! static::canManageTeacher(
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
                                    static::matchingTeacherAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Link Existing Teacher Account'
                        )
                        ->modalDescription(
                            'A Teacher-role User Account already exists for this Person. LCMS will link that account instead of creating a duplicate.'
                        )
                        ->modalSubmitActionLabel(
                            'Link Account'
                        )
                        ->action(
                            function (
                                Teacher $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::failure(
                                        'Teacher Account could not be linked.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::matchingTeacherAccount(
                                        $record
                                    );

                                if (
                                    $account === null
                                ) {
                                    static::failure(
                                        'Teacher Account could not be linked.',
                                        'No matching Teacher User Account exists for this Person.'
                                    );

                                    return;
                                }

                                if (
                                    $account->status
                                    !== AccountStatus::Active
                                ) {
                                    static::failure(
                                        'Teacher Account could not be linked.',
                                        'The existing Teacher User Account must be Active before it can be linked.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        StaffOperationalManagementService::class
                                    )->linkTeacherAccount(
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
                                        'Teacher Account could not be linked.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Teacher Account could not be linked.',
                                        'An unexpected error occurred while linking the Teacher Account.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Teacher Account linked'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateTeacherAccount'
                    )
                        ->label(
                            'Deactivate Account'
                        )
                        ->color(
                            'danger'
                        )
                        ->visible(
                            function (
                                Teacher $record
                            ): bool {
                                $account =
                                    static::linkedTeacherAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Teacher Account'
                        )
                        ->modalDescription(
                            'The Teacher operational record, Person identity, class history, and User Account record will be preserved. Only sign-in access will be deactivated.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Account'
                        )
                        ->action(
                            fn(
                                Teacher $record
                            ) =>
                            static::runTeacherAccountLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateTeacherAccount'
                    )
                        ->label(
                            'Activate Account'
                        )
                        ->color(
                            'success'
                        )
                        ->visible(
                            function (
                                Teacher $record
                            ): bool {
                                $account =
                                    static::linkedTeacherAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Deactivated;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Teacher Account'
                        )
                        ->modalDescription(
                            'The User Account will return to Active status. This does not automatically activate a deactivated Teacher operational record.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Account'
                        )
                        ->action(
                            fn(
                                Teacher $record
                            ) =>
                            static::runTeacherAccountLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'reissueTeacherCredentials'
                    )
                        ->label(
                            'Reissue Credentials'
                        )
                        ->color(
                            'warning'
                        )
                        ->visible(
                            function (
                                Teacher $record
                            ): bool {
                                $account =
                                    static::linkedTeacherAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active
                                    && static::isTeacherOperationallyActive(
                                        $record
                                    );
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Reissue Teacher Sign-in Credentials'
                        )
                        ->modalDescription(
                            'A new temporary password will be generated. The previous password will immediately become invalid and the Teacher will be required to change the new temporary password after signing in.'
                        )
                        ->modalSubmitActionLabel(
                            'Reissue Credentials'
                        )
                        ->action(
                            function (
                                Teacher $record
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
                                    static::linkedTeacherAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The linked Teacher User Account could not be resolved.'
                                    );

                                    return;
                                }

                                if (
                                    ! static::isTeacherOperationallyActive(
                                        $record
                                    )
                                ) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'Credentials cannot be reissued while the Teacher operational record is deactivated.'
                                    );

                                    return;
                                }

                                /*
             * The hash lets us distinguish:
             *
             * 1. failure before password issuance; and
             * 2. email failure after the new password
             *    has already committed.
             */
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
                                        ? $exception
                                        ->getMessage()
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
            Teacher::withoutGlobalScopes()
            ->with([
                'person',
                'user.role',
            ])
            ->withCount([
                'assignedCourseClasses',
                'classSchedules',
                'classSessions',
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
            ! $record instanceof Teacher
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
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' =>
            ListTeachers
                ::route('/'),

            'view' =>
            ViewTeacher
                ::route(
                    '/{record}'
                ),
        ];
    }

    public static function linkedTeacherAccount(
        Teacher $record
    ): ?User {
        if (
            ! static::canManageTeacher(
                $record
            )
        ) {
            return null;
        }

        /*
     * Re-resolve Teacher through the authorized Resource
     * scope instead of trusting mutable user_id /
     * person_id / center_id values.
     */
        $teacher =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $teacher === null
            || $teacher->user_id
            === null
            || $teacher->person_id
            === null
        ) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $teacher->user_id
            )
            ->where(
                'center_id',
                $teacher->center_id
            )
            ->where(
                'person_id',
                $teacher->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::Teacher
                        ->value
                )
            )
            ->first();
    }

    private static function isTeacherOperationallyActive(
        Teacher $record
    ): bool {
        if (
            ! static::canManageTeacher(
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

    public static function matchingTeacherAccount(
        Teacher $record
    ): ?User {
        if (
            ! static::canManageTeacher(
                $record
            )
            || $record->user_id
            !== null
        ) {
            return null;
        }

        /*
     * Resolve the Teacher again through the authorized
     * Resource query instead of trusting mutable
     * in-memory Center / Person state.
     */
        $teacher =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $teacher === null
            || $teacher->person_id
            === null
        ) {
            return null;
        }

        /*
     * Return an existing Teacher-role account even when
     * deactivated.
     *
     * This intentionally prevents the Create Account
     * action from attempting to create a duplicate role
     * account for the same Person.
     */
        return User::withoutGlobalScopes()
            ->with('role')
            ->where(
                'center_id',
                $teacher->center_id
            )
            ->where(
                'person_id',
                $teacher->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::Teacher
                        ->value
                )
            )
            ->whereNotExists(
                function (
                    $query
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from('teachers')
                        ->whereColumn(
                            'teachers.user_id',
                            'users.id'
                        );
                }
            )
            ->orderBy('id')
            ->first();
    }

    private static function canManageTeacher(
        Teacher $record
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
        Teacher $record
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

    public static function authorizedTeacherPerson(
        Teacher $record
    ): ?Person {
        if (
            ! static::canEditSharedIdentity(
                $record
            )
        ) {
            return null;
        }

        /*
     * Resolve Teacher again through the Resource's
     * authorized Center query. Never trust mutable
     * in-memory person_id / center_id.
     */
        $teacher =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $teacher === null
            || $teacher->person_id
            === null
        ) {
            return null;
        }

        return Person::withoutGlobalScopes()
            ->whereKey(
                $teacher->person_id
            )
            ->where(
                'center_id',
                $teacher->center_id
            )
            ->first();
    }

    private static function runTeacherAccountLifecycle(
        Teacher $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Teacher Account lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        $account =
            static::linkedTeacherAccount(
                $record
            );

        if ($account === null) {
            static::failure(
                'Teacher Account lifecycle could not be changed.',
                'The linked Teacher User Account could not be resolved.'
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
                    'Unsupported Teacher Account lifecycle operation.'
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
                'Teacher Account lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (
            Throwable) {
            static::failure(
                'Teacher Account lifecycle could not be changed.',
                'An unexpected error occurred while changing the Teacher Account lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Teacher Account activated'
                    : 'Teacher Account deactivated'
            )
            ->success()
            ->send();
    }

    private static function runTeacherLifecycle(
        Teacher $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Teacher lifecycle could not be changed.',
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
                $service->activateTeacher(
                    $actor,
                    $record
                ),

                'deactivate' =>
                $service->deactivateTeacher(
                    $actor,
                    $record
                ),

                default =>
                throw new LogicException(
                    'Unsupported Teacher lifecycle operation.'
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
                'Teacher lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Teacher lifecycle could not be changed.',
                'An unexpected error occurred while changing the Teacher lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Teacher activated'
                    : 'Teacher deactivated'
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

        /*
         * Staff administration is Center-wide.
         *
         * A Branch-scoped request must not silently
         * become Center-wide Staff administration.
         */
        $branchContext =
            app(
                BranchContext::class
            );

        if (
            ! $branchContext
                ->isCenterWide()
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