<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\User;
use App\Services\Branches\BranchManagementService;
use App\Support\Enums\BranchStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Throwable;

class ListBranches extends ListRecords
{
    protected static string $resource =
    BranchResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('createBranch')
                ->label('Create Branch')
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    BranchResource::canViewAny()
                )
                ->modalHeading(
                    'Create Branch'
                )
                ->modalDescription(
                    'Create a Branch inside the current Language Center and configure its basic contact and working-hours information.'
                )
                ->modalSubmitActionLabel(
                    'Create Branch'
                )
                ->schema(
                    BranchResource
                        ::branchFormFields(
                            true
                        )
                )
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

                        try {
                            $data['working_hours'] =
                                BranchResource
                                ::workingHoursPayload(
                                    $data['working_hours'] ?? []
                                );

                            $data['status'] =
                                BranchStatus::tryFrom(
                                    (string) (
                                        $data['status']
                                        ?? ''
                                    )
                                )
                                ?? BranchStatus::Active;

                            $branch =
                                app(
                                    BranchManagementService::class
                                )->create(
                                    $actor,
                                    $data
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
                        } catch (Throwable) {
                            $this->creationFailure(
                                'An unexpected error occurred while creating the Branch.'
                            );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Branch created'
                            )
                            ->body(
                                $branch->name
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
                'Branch could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}
