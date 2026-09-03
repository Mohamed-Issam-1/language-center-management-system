<?php

namespace App\Filament\Resources\AttendanceStatuses\Pages;

use App\Filament\Resources\AttendanceStatuses\AttendanceStatusResource;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

class ListAttendanceStatuses extends ListRecords
{
    protected static string $resource =
    AttendanceStatusResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createAttendanceStatus'
            )
                ->label(
                    'Create Attendance Status'
                )
                ->color(
                    'primary'
                )
                ->visible(
                    fn(): bool =>
                    AttendanceStatusResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Attendance Status'
                )
                ->modalDescription(
                    'Create a new Attendance Status for the current Center. New statuses always start Active.'
                )
                ->modalSubmitActionLabel(
                    'Create Status'
                )
                ->schema([
                    TextInput::make(
                        'name'
                    )
                        ->label(
                            'Name'
                        )
                        ->required()
                        ->maxLength(100),

                    TextInput::make(
                        'code'
                    )
                        ->label(
                            'Code'
                        )
                        ->required()
                        ->maxLength(50),

                    TextInput::make(
                        'contribution_value'
                    )
                        ->label(
                            'Contribution Value'
                        )
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->required(),
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
                            $this
                                ->statusCreationFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        try {
                            $status =
                                app(
                                    AttendanceStatusManagementService::class
                                )->create(
                                    $actor,
                                    [
                                        /*
                                         * Ownership and lifecycle
                                         * fields are intentionally
                                         * not supplied by Filament.
                                         *
                                         * The service derives Center
                                         * ownership and always creates
                                         * the Status as Active.
                                         */
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
                            $exception
                        ) {
                            $this
                                ->statusCreationFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Attendance Status created'
                            )
                            ->body(
                                'Attendance Status '
                                    . $status->name
                                    . ' ('
                                    . $status->code
                                    . ') was created successfully.'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    private function statusCreationFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Attendance Status could not be created'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}