<?php

namespace App\Filament\Resources\CenterOwners\Pages;

use App\Filament\Resources\CenterOwners\CenterOwnerResource;
use App\Models\Center;
use App\Models\User;
use App\Services\Accounts\PlatformCenterOwnerProvisioningService;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ListCenterOwners extends ListRecords
{
    protected static string $resource =
    CenterOwnerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createCenterOwner'
            )
                ->label(
                    'Create Center Owner'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    CenterOwnerResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Center Owner'
                )
                ->modalDescription(
                    'Create the privileged Center Owner account for a Language Center. LCMS generates the 8-digit login identifier and a temporary password automatically.'
                )
                ->modalSubmitActionLabel(
                    'Create Center Owner'
                )
                ->schema([
                    Select::make(
                        'center_id'
                    )
                        ->label(
                            'Language Center'
                        )
                        ->options(
                            fn(): array =>
                            Center::withoutGlobalScopes()
                                ->orderBy('name')
                                ->pluck(
                                    'name',
                                    'id'
                                )
                                ->all()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make(
                        'full_name'
                    )
                        ->label(
                            'Full Name'
                        )
                        ->required()
                        ->maxLength(255),

                    TextInput::make(
                        'national_id_number'
                    )
                        ->label(
                            'National ID'
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
                            now()->toDateString()
                        ),

                    TextInput::make(
                        'city_of_residence'
                    )
                        ->label(
                            'City of Residence'
                        )
                        ->required()
                        ->maxLength(255),

                    TextInput::make(
                        'email'
                    )
                        ->label('Email')
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
                        ->maxLength(255)
                        ->helperText(
                            'Include the country code, for example +970...'
                        ),
                ])
                ->action(
                    function (
                        array $data
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor instanceof User
                        ) {
                            $this->creationFailure(
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        $center =
                            Center::withoutGlobalScopes()
                            ->whereKey(
                                $data['center_id']
                            )
                            ->first();

                        if (
                            $center === null
                        ) {
                            $this->creationFailure(
                                'The selected Language Center no longer exists.'
                            );

                            return;
                        }

                        try {
                            $result =
                                app(
                                    PlatformCenterOwnerProvisioningService::class
                                )->provision(
                                    $actor,
                                    $center,
                                    [
                                        'national_id_number' =>
                                        $data['national_id_number'],

                                        'full_name' =>
                                        $data['full_name'],

                                        'date_of_birth' =>
                                        $data['date_of_birth'],

                                        'city_of_residence' =>
                                        $data['city_of_residence'],

                                        'email' =>
                                        $data['email'],

                                        'phone_number' =>
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
                            $this->creationFailure(
                                $exception
                                    ->getMessage()
                            );

                            return;
                        } catch (
                            Throwable) {
                            $this->creationFailure(
                                'An unexpected error occurred while creating the Center Owner.'
                            );

                            return;
                        }

                        /*
                         * Database provisioning has committed.
                         *
                         * Credential delivery is deliberately
                         * performed afterwards so an external mail
                         * failure never rolls back the account.
                         */
                        try {
                            app(
                                RegistrationCredentialsDeliveryService::class
                            )->deliverAccountCredentials(
                                $result['account'],
                                $result['temporary_password']
                            );
                        } catch (
                            Throwable) {
                            Notification::make()
                                ->title(
                                    'Center Owner created'
                                )
                                ->body(
                                    'The account and temporary password were created successfully, but the credentials email could not be delivered. Use Reissue Credentials from the Center Owners table.'
                                )
                                ->warning()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Center Owner created'
                            )
                            ->body(
                                'The Center Owner account was created successfully and the sign-in credentials were sent.'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    private function creationFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Center Owner could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}