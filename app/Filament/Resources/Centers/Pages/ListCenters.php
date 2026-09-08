<?php

namespace App\Filament\Resources\Centers\Pages;

use App\Filament\Resources\Centers\CenterResource;
use App\Models\Center;
use App\Models\User;
use App\Services\Centers\CenterManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ListCenters extends ListRecords
{
    protected static string $resource =
    CenterResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCenter')
                ->label('Create Center')
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    CenterResource::canViewAny()
                )
                ->modalHeading(
                    'Create Language Center'
                )
                ->modalDescription(
                    'Create a new Center. New Centers always begin Suspended and must be activated explicitly after configuration.'
                )
                ->modalSubmitActionLabel(
                    'Create Center'
                )
                ->schema([
                    TextInput::make('name')
                        ->label('Center Name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('code')
                        ->label('Center Code')
                        ->required()
                        ->maxLength(50),

                    TextInput::make(
                        'identifier_code'
                    )
                        ->label(
                            'Identifier Code'
                        )
                        ->required()
                        ->length(2)
                        ->regex(
                            '/^(0[1-9]|[1-9][0-9])$/'
                        )
                        ->helperText(
                            'A permanent two-digit value from 01 through 99. It cannot be changed later.'
                        ),

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
                        ->default('Asia/Gaza')
                        ->maxLength(100),

                    TextInput::make(
                        'operating_currency_code'
                    )
                        ->label(
                            'Operating Currency'
                        )
                        ->required()
                        ->default('USD')
                        ->length(3)
                        ->helperText(
                            'Three-letter currency code.'
                        ),

                    Textarea::make('address')
                        ->label('Address')
                        ->rows(3)
                        ->maxLength(255)
                        ->columnSpanFull(),
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

                        /*
                         * Normalize presentation-oriented values
                         * before passing them to the authoritative
                         * domain service.
                         */
                        $data['code'] =
                            trim(
                                (string)
                                $data['code']
                            );

                        $data['name'] =
                            trim(
                                (string)
                                $data['name']
                            );

                        $data['operating_currency_code'] =
                            strtoupper(
                                trim(
                                    (string)
                                    $data['operating_currency_code']
                                )
                            );

                        try {
                            $center =
                                app(
                                    CenterManagementService::class
                                )->create(
                                    $actor,
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
                            $this->creationFailure(
                                $exception->getMessage()
                            );

                            return;
                        } catch (
                            Throwable) {
                            $this->creationFailure(
                                'An unexpected error occurred while creating the Center.'
                            );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Center created'
                            )
                            ->body(
                                $center->name
                                    . ' was created successfully and is currently Suspended.'
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
                'Center could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}