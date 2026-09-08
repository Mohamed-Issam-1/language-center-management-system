<?php

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
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
use App\Services\Students\StudentManagementService;
use App\Support\Enums\BranchStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Services\Students\StudentAccountProvisioningService;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\QueryException;
use App\Services\Accounts\UserAccountManagementService;
use App\Services\Registration\RegistrationCredentialsReissueService;
use App\Models\Person;
use App\Services\Accounts\CenterPersonIdentityUpdateService;
use Filament\Forms\Components\DatePicker;
use Filament\Actions\ActionGroup;

class StudentResource extends Resource
{
    protected static ?string $model =
    Student::class;

    protected static ?string $navigationLabel =
    'Students';

    protected static ?string $modelLabel =
    'Student';

    protected static ?string $pluralModelLabel =
    'Students';

    protected static string | \UnitEnum | null $navigationGroup =
    'People';

    protected static ?int $navigationSort =
    10;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Student Identity'
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
                    'Student Record'
                )
                    ->schema([
                        TextEntry::make(
                            'branch.name'
                        )
                            ->label('Branch'),

                        TextEntry::make(
                            'status'
                        )
                            ->label(
                                'Student Status'
                            )
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::studentStatusLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::studentStatusColor(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'archived_at'
                        )
                            ->label(
                                'Archived At'
                            )
                            ->dateTime(
                                'Y-m-d H:i'
                            )
                            ->placeholder(
                                'Not archived'
                            ),

                        TextEntry::make(
                            'enrollments_count'
                        )
                            ->label(
                                'Enrollments'
                            ),

                        TextEntry::make(
                            'payments_count'
                        )
                            ->label(
                                'Payments'
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
                        'Student'
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
                    'branch.name'
                )
                    ->label(
                        'Branch'
                    )
                    ->searchable()
                    ->sortable()
                    ->wrap(),

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
                        'Student Status'
                    )
                    ->badge()
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::studentStatusLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::studentStatusColor(
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
                    'enrollments_count'
                )
                    ->label(
                        'Enrollments'
                    )
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'branch_id'
                )
                    ->label(
                        'Branch'
                    )
                    ->options(
                        fn(): array =>
                        static::branchOptions()
                    ),

                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Student Status'
                    )
                    ->options([
                        StudentStatus::Active
                            ->value =>
                        'Active',

                        StudentStatus::Archived
                            ->value =>
                        'Archived',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'editStudentIdentity'
                    )
                        ->label(
                            'Edit Identity'
                        )
                        ->color(
                            'primary'
                        )
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canEditSharedIdentity(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Edit Student Identity'
                        )
                        ->modalDescription(
                            'These fields belong to the shared Person identity. National ID and Center cannot be changed through this action.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Identity'
                        )
                        ->fillForm(
                            function (
                                Student $record
                            ): array {
                                $person =
                                    static::authorizedStudentPerson(
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
                                Student $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Student identity could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $person =
                                    static::authorizedStudentPerson(
                                        $record
                                    );

                                if ($person === null) {
                                    static::failure(
                                        'Student identity could not be updated.',
                                        'The Student Person identity could not be resolved.'
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
                                        'Student identity could not be updated.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Student identity could not be updated.',
                                        'An unexpected error occurred while updating the Student identity.'
                                    );

                                    return;
                                }

                                $record->refresh();
                                $record->load(
                                    'person'
                                );

                                Notification::make()
                                    ->title(
                                        'Student identity updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'createStudentAccount'
                    )
                        ->label('Create Account')
                        ->color('success')
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canManageStudentAccounts()
                                && $record->status
                                === StudentStatus::Active
                                && $record->user_id
                                === null
                                && static::matchingStudentAccount(
                                    $record
                                ) === null
                        )
                        ->modalHeading(
                            'Create Student Account'
                        )
                        ->modalDescription(
                            'LCMS will generate the login identifier and a temporary password, link the new account to this Student, and send the credentials by email.'
                        )
                        ->modalSubmitActionLabel(
                            'Create & Send Credentials'
                        )
                        ->fillForm(
                            fn(
                                Student $record
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
                                    'Initial login credentials will be sent to this address.'
                                ),
                        ])
                        ->action(
                            function (
                                Student $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Student Account could not be created.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $result =
                                        app(
                                            StudentAccountProvisioningService::class
                                        )->provision(
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
                                        'Student Account could not be created.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (
                                    QueryException) {
                                    static::failure(
                                        'Student Account could not be created.',
                                        'Database constraints rejected the Student Account.'
                                    );

                                    return;
                                } catch (
                                    Throwable) {
                                    static::failure(
                                        'Student Account could not be created.',
                                        'An unexpected error occurred while creating the Student Account.'
                                    );

                                    return;
                                }

                                /*
             * The account transaction has committed before
             * external email delivery begins.
             *
             * Never roll the account back because SMTP failed.
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
                                            'Student Account created'
                                        )
                                        ->body(
                                            'The account was created and linked successfully, but the credentials email could not be delivered. Use Reissue Credentials after UI-7A3B is enabled.'
                                        )
                                        ->warning()
                                        ->persistent()
                                        ->send();

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Student Account created'
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
                        'linkExistingStudentAccount'
                    )
                        ->label(
                            'Link Existing Account'
                        )
                        ->color('primary')
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canManageStudentAccounts()
                                && $record->status
                                === StudentStatus::Active
                                && $record->user_id
                                === null
                                && static::matchingStudentAccount(
                                    $record
                                ) !== null
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Link Existing Student Account'
                        )
                        ->modalDescription(
                            'A Student-role User Account already exists for the same Person. Link that existing account instead of creating a duplicate.'
                        )
                        ->modalSubmitActionLabel(
                            'Link Account'
                        )
                        ->action(
                            function (
                                Student $record
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Student Account could not be linked.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                $account =
                                    static::matchingStudentAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Student Account could not be linked.',
                                        'No eligible Student Account exists for this Person.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        StudentManagementService::class
                                    )->linkAccount(
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
                                        'Student Account could not be linked.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Student Account could not be linked.',
                                        'An unexpected error occurred while linking the Student Account.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Student Account linked'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'deactivateStudentAccount'
                    )
                        ->label('Deactivate Account')
                        ->color('danger')
                        ->visible(
                            function (
                                Student $record
                            ): bool {
                                $account =
                                    static::linkedStudentAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Student Account'
                        )
                        ->modalDescription(
                            'The Student, Person, enrollments, financial history, and User Account will be preserved. The account will no longer be able to sign in.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Account'
                        )
                        ->action(
                            fn(
                                Student $record
                            ) =>
                            static::runStudentAccountLifecycle(
                                $record,
                                'deactivate'
                            )
                        ),

                    Action::make(
                        'activateStudentAccount'
                    )
                        ->label('Activate Account')
                        ->color('success')
                        ->visible(
                            function (
                                Student $record
                            ): bool {
                                $account =
                                    static::linkedStudentAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Deactivated;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Student Account'
                        )
                        ->modalDescription(
                            'The Student User Account will return to Active status.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Account'
                        )
                        ->action(
                            fn(
                                Student $record
                            ) =>
                            static::runStudentAccountLifecycle(
                                $record,
                                'activate'
                            )
                        ),

                    Action::make(
                        'reissueStudentCredentials'
                    )
                        ->label('Reissue Credentials')
                        ->color('warning')
                        ->visible(
                            function (
                                Student $record
                            ): bool {
                                $account =
                                    static::linkedStudentAccount(
                                        $record
                                    );

                                return $account !== null
                                    && $account->status
                                    === AccountStatus::Active;
                            }
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Reissue Student Sign-in Credentials'
                        )
                        ->modalDescription(
                            'A new temporary password will be generated. The previous password will immediately become invalid and the Student will be required to change the new temporary password after signing in.'
                        )
                        ->modalSubmitActionLabel(
                            'Reissue Credentials'
                        )
                        ->action(
                            function (
                                Student $record
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
                                    static::linkedStudentAccount(
                                        $record
                                    );

                                if ($account === null) {
                                    static::failure(
                                        'Credentials could not be reissued.',
                                        'The linked Student User Account could not be resolved.'
                                    );

                                    return;
                                }

                                /*
             * Distinguish a failure before credential
             * issuance from an email failure after the new
             * password hash has already committed.
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

                    Action::make(
                        'moveStudentBranch'
                    )
                        ->label('Move Branch')
                        ->color('primary')
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                === StudentStatus::Active
                                && auth()->user()
                                ?->systemRole()
                                === SystemRole::CenterOwner
                        )
                        ->modalHeading(
                            'Move Student to Another Branch'
                        )
                        ->modalDescription(
                            'The destination Branch must be active and belong to the same Center.'
                        )
                        ->modalSubmitActionLabel(
                            'Move Student'
                        )
                        ->schema([
                            Select::make(
                                'branch_id'
                            )
                                ->label(
                                    'Destination Branch'
                                )
                                ->options(
                                    fn(
                                        Student $record
                                    ): array =>
                                    static::activeBranchOptions(
                                        $record->branch_id
                                    )
                                )
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->required(),
                        ])
                        ->action(
                            function (
                                Student $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Student could not be moved.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        StudentManagementService::class
                                    )->update(
                                        $actor,
                                        $record,
                                        [
                                            'branch_id' =>
                                            (int)
                                            $data['branch_id'],
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
                                        'Student could not be moved.',
                                        $exception->getMessage()
                                    );

                                    return;
                                } catch (Throwable) {
                                    static::failure(
                                        'Student could not be moved.',
                                        'An unexpected error occurred while moving the Student.'
                                    );

                                    return;
                                }

                                $record->refresh();

                                Notification::make()
                                    ->title(
                                        'Student moved'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'archiveStudent'
                    )
                        ->label('Archive')
                        ->color('danger')
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                === StudentStatus::Active
                                && static::canManageStudentRecords()
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Archive Student'
                        )
                        ->modalDescription(
                            'The Student record and historical relationships will be preserved.'
                        )
                        ->modalSubmitActionLabel(
                            'Archive Student'
                        )
                        ->action(
                            fn(
                                Student $record
                            ) =>
                            static::runStudentLifecycle(
                                $record,
                                'archive'
                            )
                        ),

                    Action::make(
                        'restoreStudent'
                    )
                        ->label('Restore')
                        ->color('success')
                        ->visible(
                            fn(
                                Student $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                === StudentStatus::Archived
                                && static::canManageStudentRecords()
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Restore Student'
                        )
                        ->modalDescription(
                            'The Student can be restored only if the assigned Branch is active.'
                        )
                        ->modalSubmitActionLabel(
                            'Restore Student'
                        )
                        ->action(
                            fn(
                                Student $record
                            ) =>
                            static::runStudentLifecycle(
                                $record,
                                'restore'
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
            Student::withoutGlobalScopes()
            ->with([
                'branch',
                'person',
                'user.role',
            ])
            ->withCount([
                'enrollments',
                'payments',
            ]);

        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return static::denyQuery(
                $query
            );
        }

        $query->where(
            'center_id',
            $scope['center_id']
        );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->where(
                'branch_id',
                $scope['branch_id']
            );
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::authorizedScope()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record
                instanceof Student
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
     * Student creation remains outside native Filament CRUD.
     *
     * LCMS public registration and approval already own the
     * complete identity + account + operational-record workflow.
     *
     * Student lifecycle and Branch changes will be exposed
     * later as explicit service-backed actions.
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
            ListStudents
                ::route('/'),

            'view' =>
            ViewStudent
                ::route(
                    '/{record}'
                ),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function branchOptions(): array
    {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        $query =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $scope['center_id']
            );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->whereKey(
                $scope['branch_id']
            );
        }

        return $query
            ->orderBy('name')
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    /**
     * @return array{
     *     center_id:int,
     *     branch_id:?int
     * }|null
     */
    private static function authorizedScope(): ?array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id
            === null
            || ! $actor->hasPermission(
                SystemPermission
                ::ViewStudentRecords
            )
        ) {
            return null;
        }

        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant
                ->isCenterScoped()
            || $tenant->centerId()
            !== $actor->center_id
        ) {
            return null;
        }

        $branchContext =
            app(
                BranchContext::class
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $branchContext
                    ->isCenterWide()
            ) {
                return null;
            }

            return [
                'center_id' =>
                $actor->center_id,

                'branch_id' =>
                null,
            ];
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
            || ! $branchContext
                ->isBranchScoped()
        ) {
            return null;
        }

        $branch =
            $branchContext->branch();

        if (
            $branch === null
            || $branch->center_id
            !== $actor->center_id
        ) {
            return null;
        }

        $hasAssignment =
            $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $actor->center_id
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->exists();

        if (! $hasAssignment) {
            return null;
        }

        return [
            'center_id' =>
            $actor->center_id,

            'branch_id' =>
            $branch->id,
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function activeBranchOptions(
        ?int $excludeBranchId = null
    ): array {
        $scope =
            static::authorizedScope();

        if (
            $scope === null
            || $scope['branch_id']
            !== null
        ) {
            return [];
        }

        $query =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'status',
                BranchStatus::Active
                    ->value
            );

        if (
            $excludeBranchId
            !== null
        ) {
            $query->where(
                'id',
                '!=',
                $excludeBranchId
            );
        }

        return $query
            ->orderBy('name')
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    private static function canManageStudentRecords(): bool
    {
        $actor =
            auth()->user();

        return $actor
            instanceof User
            && $actor->hasPermission(
                SystemPermission
                ::ManageStudentRecords
            )
            && in_array(
                $actor->systemRole(),
                [
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                ],
                true
            );
    }

    public static function linkedStudentAccount(
        Student $record
    ): ?User {
        if (
            ! static::canManageStudentAccounts()
        ) {
            return null;
        }

        /*
     * Re-read the Student through the authorized
     * Resource query.
     *
     * Never trust a mutable in-memory user_id,
     * center_id, person_id, or branch_id.
     */
        $student =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $student === null
            || $student->user_id === null
            || $student->person_id === null
        ) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->with('role')
            ->whereKey(
                $student->user_id
            )
            ->where(
                'center_id',
                $student->center_id
            )
            ->where(
                'person_id',
                $student->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::Student
                        ->value
                )
            )
            ->first();
    }

    private static function canEditSharedIdentity(
        Student $record
    ): bool {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->systemRole()
            !== SystemRole::CenterOwner
            || ! $actor->hasPermission(
                SystemPermission
                ::ManageStudentRecords
            )
            || ! $actor->hasPermission(
                SystemPermission
                ::ManageStaffAccounts
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

        return $record->person_id
            !== null;
    }

    public static function authorizedStudentPerson(
        Student $record
    ): ?Person {
        if (
            ! static::canEditSharedIdentity(
                $record
            )
        ) {
            return null;
        }

        /*
     * Re-resolve the Student through the authorized
     * Resource query instead of trusting mutable
     * in-memory Center / Person information.
     */
        $student =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if (
            $student === null
            || $student->person_id
            === null
        ) {
            return null;
        }

        return Person::withoutGlobalScopes()
            ->whereKey(
                $student->person_id
            )
            ->where(
                'center_id',
                $student->center_id
            )
            ->first();
    }

    private static function canManageStudentAccounts(): bool
    {
        $actor =
            auth()->user();

        return $actor
            instanceof User
            && static::authorizedScope()
            !== null
            && $actor->hasPermission(
                SystemPermission
                ::ManageStudentAccounts
            )
            && in_array(
                $actor->systemRole(),
                [
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                ],
                true
            );
    }

    public static function matchingStudentAccount(
        Student $record
    ): ?User {
        if (
            ! static::canView(
                $record
            )
            || $record->user_id
            !== null
            || $record->center_id
            === null
            || $record->person_id
            === null
        ) {
            return null;
        }

        /*
     * Query globally and then explicitly constrain
     * Center + Person + Role.
     *
     * The NOT EXISTS avoids relationship global scopes
     * accidentally hiding an account linked to another
     * Student record.
     */
        return User::withoutGlobalScopes()
            ->with('role')
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'person_id',
                $record->person_id
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::Student
                        ->value
                )
            )
            ->whereNotExists(
                function (
                    $query
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from('students')
                        ->whereColumn(
                            'students.user_id',
                            'users.id'
                        );
                }
            )
            ->orderBy('id')
            ->first();
    }

    private static function runStudentAccountLifecycle(
        Student $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Student Account lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        $account =
            static::linkedStudentAccount(
                $record
            );

        if ($account === null) {
            static::failure(
                'Student Account lifecycle could not be changed.',
                'The linked Student User Account could not be resolved.'
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
                    'Unsupported Student Account lifecycle operation.'
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
                'Student Account lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Student Account lifecycle could not be changed.',
                'An unexpected error occurred while changing the Student Account lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'activate'
                    ? 'Student Account activated'
                    : 'Student Account deactivated'
            )
            ->success()
            ->send();
    }

    private static function runStudentLifecycle(
        Student $record,
        string $operation
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Student lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    StudentManagementService::class
                );

            match ($operation) {
                'archive' =>
                $service->archive(
                    $actor,
                    $record
                ),

                'restore' =>
                $service->restore(
                    $actor,
                    $record
                ),

                default =>
                throw new LogicException(
                    'Unsupported Student lifecycle operation.'
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
                'Student lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Student lifecycle could not be changed.',
                'An unexpected error occurred while changing the Student lifecycle.'
            );

            return;
        }

        $record->refresh();

        Notification::make()
            ->title(
                $operation === 'archive'
                    ? 'Student archived'
                    : 'Student restored'
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

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function studentStatusLabel(
        mixed $state
    ): string {
        $status =
            $state
            instanceof StudentStatus
            ? $state
            : StudentStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            StudentStatus::Active =>
            'Active',

            StudentStatus::Archived =>
            'Archived',

            default =>
            'Unknown',
        };
    }

    private static function studentStatusColor(
        mixed $state
    ): string {
        $status =
            $state
            instanceof StudentStatus
            ? $state
            : StudentStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            StudentStatus::Active =>
            'success',

            StudentStatus::Archived =>
            'gray',

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
            $state
            instanceof AccountStatus
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
            $state
            instanceof AccountStatus
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