<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
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

class ListCourses extends ListRecords
{
    protected static string $resource =
    CourseResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createCourse'
            )
                ->label(
                    'Create Course'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    CourseResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Course'
                )
                ->modalDescription(
                    'Select an Active Academic Level. Its parent Language is derived automatically and cannot be changed later.'
                )
                ->modalSubmitActionLabel(
                    'Create Course'
                )
                ->schema([
                    Select::make(
                        'academic_level_id'
                    )
                        ->label(
                            'Academic Level'
                        )
                        ->options(
                            fn(): array =>
                            CourseResource
                                ::academicLevelOptions(
                                    true
                                )
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->helperText(
                            'Displayed as Language · Academic Level.'
                        ),

                    ...CourseResource
                        ::courseFields(),
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

                        $academicLevel =
                            CourseResource
                            ::resolveCreationAcademicLevel(
                                $data['academic_level_id'] ?? null
                            );

                        if (
                            $academicLevel === null
                            || $academicLevel
                            ->language === null
                        ) {
                            $this
                                ->creationFailure(
                                    'An Active Academic Level under an Active Language must be selected.'
                                );

                            return;
                        }

                        try {
                            $course =
                                app(
                                    AcademicCatalogManagementService::class
                                )->createCourse(
                                    $actor,
                                    $academicLevel
                                        ->language,
                                    $academicLevel,
                                    [
                                        'name' =>
                                        $data['name'],

                                        'code' =>
                                        $data['code'],

                                        'description' =>
                                        $data['description']
                                            ?? null,

                                        'duration_weeks' =>
                                        $data['duration_weeks'],

                                        'total_hours' =>
                                        $data['total_hours'],

                                        'default_fee' =>
                                        $data['default_fee'],

                                        'passing_grade' =>
                                        $data['passing_grade'],

                                        'minimum_attendance' =>
                                        $data['minimum_attendance'],
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
                                    'An unexpected error occurred while creating the Course.'
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Course created'
                            )
                            ->body(
                                $course->name
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
                'Course could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}
