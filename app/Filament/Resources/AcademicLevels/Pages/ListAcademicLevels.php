<?php

namespace App\Filament\Resources\AcademicLevels\Pages;

use App\Filament\Resources\AcademicLevels\AcademicLevelResource;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ListAcademicLevels extends ListRecords
{
    protected static string $resource =
    AcademicLevelResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createAcademicLevel'
            )
                ->label(
                    'Create Academic Level'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    AcademicLevelResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Academic Level'
                )
                ->modalDescription(
                    'Create an Academic Level under an Active Language. The parent Language cannot be changed later.'
                )
                ->modalSubmitActionLabel(
                    'Create Academic Level'
                )
                ->schema([
                    Select::make(
                        'language_id'
                    )
                        ->label('Language')
                        ->options(
                            fn(): array =>
                            AcademicLevelResource
                                ::languageOptions(
                                    true
                                )
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),

                    ...AcademicLevelResource
                        ::levelFields(),
                ])
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
                            $this
                                ->creationFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        $language =
                            AcademicLevelResource
                            ::resolveCreationLanguage(
                                $data['language_id'] ?? null
                            );

                        if ($language === null) {
                            $this
                                ->creationFailure(
                                    'An Active Language within the authorized Center must be selected.'
                                );

                            return;
                        }

                        try {
                            $level =
                                app(
                                    AcademicCatalogManagementService::class
                                )->createAcademicLevel(
                                    $actor,
                                    $language,
                                    [
                                        'name' =>
                                        $data['name'],

                                        'code' =>
                                        $data['code'],

                                        'sequence_number' =>
                                        (int)
                                        $data['sequence_number'],

                                        'description' =>
                                        $data['description']
                                            ?? null,
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
                            $this
                                ->creationFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        } catch (
                            Throwable) {
                            $this
                                ->creationFailure(
                                    'An unexpected error occurred while creating the Academic Level.'
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Academic Level created'
                            )
                            ->body(
                                $level->name
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
                'Academic Level could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}
