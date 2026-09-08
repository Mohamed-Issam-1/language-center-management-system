<?php

namespace App\Filament\Resources\Classrooms\Pages;

use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Models\User;
use App\Services\Classrooms\ClassroomManagementService;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\SystemRole;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Throwable;

class ListClassrooms extends ListRecords
{
    protected static string $resource =
    ClassroomResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createClassroom'
            )
                ->label(
                    'Create Classroom'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    ClassroomResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Classroom'
                )
                ->modalDescription(
                    'Create a Classroom under an Active Branch. Center and Branch scope are enforced by LCMS.'
                )
                ->modalSubmitActionLabel(
                    'Create Classroom'
                )
                ->schema([
                    Select::make(
                        'branch_id'
                    )
                        ->label('Branch')
                        ->options(
                            fn(): array =>
                            ClassroomResource
                                ::branchOptions(
                                    true
                                )
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(
                            fn(): bool =>
                            auth()->user()
                                ?->systemRole()
                                ===
                                SystemRole::CenterOwner
                        )
                        ->required(
                            fn(): bool =>
                            auth()->user()
                                ?->systemRole()
                                ===
                                SystemRole::CenterOwner
                        ),

                    ...ClassroomResource
                        ::informationFields(),

                    Select::make(
                        'availability_status'
                    )
                        ->label(
                            'Initial Availability'
                        )
                        ->options([
                            ClassroomAvailabilityStatus
                            ::Available
                                ->value =>
                            'Available',

                            ClassroomAvailabilityStatus
                            ::Unavailable
                                ->value =>
                            'Unavailable',
                        ])
                        ->default(
                            ClassroomAvailabilityStatus
                            ::Available
                                ->value
                        )
                        ->required(),

                    Select::make('status')
                        ->label(
                            'Initial Status'
                        )
                        ->options([
                            ClassroomStatus::Active
                                ->value =>
                            'Active',

                            ClassroomStatus
                            ::Deactivated
                                ->value =>
                            'Deactivated',
                        ])
                        ->default(
                            ClassroomStatus::Active
                                ->value
                        )
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
                            $this->creationFailure(
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        $branch =
                            ClassroomResource
                            ::resolveCreationBranch(
                                $data['branch_id'] ?? null
                            );

                        if ($branch === null) {
                            $this->creationFailure(
                                'An Active Branch within the authorized scope must be selected.'
                            );

                            return;
                        }

                        $availability =
                            ClassroomAvailabilityStatus
                            ::tryFrom(
                                (string) (
                                    $data['availability_status']
                                    ?? ''
                                )
                            );

                        $status =
                            ClassroomStatus
                            ::tryFrom(
                                (string) (
                                    $data['status']
                                    ?? ''
                                )
                            );

                        if (
                            $availability === null
                            || $status === null
                        ) {
                            $this->creationFailure(
                                'The Classroom availability or status is invalid.'
                            );

                            return;
                        }

                        try {
                            $classroom =
                                app(
                                    ClassroomManagementService::class
                                )->create(
                                    $actor,
                                    $branch,
                                    [
                                        'name' =>
                                        $data['name'],

                                        'code' =>
                                        $data['code'],

                                        'capacity' =>
                                        (int)
                                        $data['capacity'],

                                        'location' =>
                                        $data['location'],

                                        'availability_status' =>
                                        $availability,

                                        'status' =>
                                        $status,
                                    ]
                                );
                        } catch (
                            AuthorizationException
                            | DomainException
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
                                'An unexpected error occurred while creating the Classroom.'
                            );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Classroom created'
                            )
                            ->body(
                                $classroom->name
                                    . ' was created successfully.'
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
                'Classroom could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}