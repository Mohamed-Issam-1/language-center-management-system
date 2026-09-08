<?php

namespace App\Filament\Resources\Languages\Pages;

use App\Filament\Resources\Languages\LanguageResource;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ListLanguages extends ListRecords
{
    protected static string $resource =
    LanguageResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createLanguage'
            )
                ->label(
                    'Create Language'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    LanguageResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Language'
                )
                ->modalDescription(
                    'Create a new Language in the current Language Center. New Languages always begin Active.'
                )
                ->modalSubmitActionLabel(
                    'Create Language'
                )
                ->schema(
                    LanguageResource
                        ::languageFields()
                )
                ->action(
                    function (
                        array $data
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor
                                instanceof User
                        ) {
                            $this->creationFailure(
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        try {
                            $language =
                                app(
                                    AcademicCatalogManagementService::class
                                )->createLanguage(
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
                                $exception
                                    ->getMessage()
                            );

                            return;
                        } catch (
                            Throwable) {
                            $this->creationFailure(
                                'An unexpected error occurred while creating the Language.'
                            );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Language created'
                            )
                            ->body(
                                $language->name
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
                'Language could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}
