<?php

namespace App\Filament\Resources\CourseClasses\Pages;

use App\Filament\Resources\CourseClasses\CourseClassResource;
use App\Models\User;
use App\Services\CourseClasses\CourseClassManagementService;
use App\Support\Enums\SystemRole;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ListCourseClasses extends ListRecords
{
    protected static string $resource =
    CourseClassResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createCourseClass'
            )
                ->label(
                    'Create Course Class'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    CourseClassResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Course Class'
                )
                ->modalDescription(
                    'Create a Planned Course Class using an Active Branch, Active Course, operational Classroom, and Active Teacher.'
                )
                ->modalSubmitActionLabel(
                    'Create Course Class'
                )
                ->schema([
                    Select::make(
                        'branch_id'
                    )
                        ->label('Branch')
                        ->options(
                            fn(): array =>
                            CourseClassResource
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

                    Select::make(
                        'course_id'
                    )
                        ->label('Course')
                        ->options(
                            fn(): array =>
                            CourseClassResource
                                ::courseOptions(
                                    true
                                )
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),

                    Select::make(
                        'assigned_classroom_id'
                    )
                        ->label('Classroom')
                        ->options(
                            fn(): array =>
                            CourseClassResource
                                ::classroomOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->helperText(
                            'For a Center Owner, the selected Classroom must belong to the selected Branch.'
                        ),

                    Select::make(
                        'assigned_teacher_id'
                    )
                        ->label('Teacher')
                        ->options(
                            fn(): array =>
                            CourseClassResource
                                ::teacherOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),

                    TextInput::make(
                        'class_code'
                    )
                        ->label(
                            'Class Code'
                        )
                        ->required()
                        ->maxLength(50)
                        ->helperText(
                            'Must be unique inside the current Center.'
                        ),

                    TextInput::make('name')
                        ->label(
                            'Class Name'
                        )
                        ->required()
                        ->maxLength(255),

                    DatePicker::make(
                        'start_date'
                    )
                        ->label(
                            'Start Date'
                        )
                        ->required(),

                    DatePicker::make(
                        'end_date'
                    )
                        ->label(
                            'End Date'
                        )
                        ->required(),

                    TextInput::make(
                        'capacity'
                    )
                        ->label('Capacity')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText(
                            'Cannot exceed the selected Classroom capacity.'
                        ),

                    TextInput::make(
                        'delivery_mode'
                    )
                        ->label(
                            'Delivery Mode'
                        )
                        ->required()
                        ->maxLength(50)
                        ->placeholder(
                            'e.g. onsite'
                        )
                        ->helperText(
                            'No fixed Delivery Mode enum is currently defined by the LCMS backend.'
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
                            $this
                                ->creationFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        $branch =
                            CourseClassResource
                            ::resolveCreationBranch(
                                $data['branch_id'] ?? null
                            );

                        if ($branch === null) {
                            $this
                                ->creationFailure(
                                    'An Active Branch inside the authorized operational scope must be selected.'
                                );

                            return;
                        }

                        $course =
                            CourseClassResource
                            ::resolveCreationCourse(
                                $data['course_id'] ?? null
                            );

                        if ($course === null) {
                            $this
                                ->creationFailure(
                                    'An Active Course inside the current Center must be selected.'
                                );

                            return;
                        }

                        $classroom =
                            CourseClassResource
                            ::resolveCreationClassroom(
                                $data['assigned_classroom_id'] ?? null,
                                $branch
                            );

                        if ($classroom === null) {
                            $this
                                ->creationFailure(
                                    'The Classroom must be Active, Available, and belong to the selected Branch.'
                                );

                            return;
                        }

                        $teacher =
                            CourseClassResource
                            ::resolveCreationTeacher(
                                $data['assigned_teacher_id'] ?? null
                            );

                        if ($teacher === null) {
                            $this
                                ->creationFailure(
                                    'An Active Teacher inside the current Center must be selected.'
                                );

                            return;
                        }

                        try {
                            $courseClass =
                                app(
                                    CourseClassManagementService::class
                                )->create(
                                    $actor,
                                    $branch,
                                    $course,
                                    $classroom,
                                    $teacher,
                                    [
                                        'class_code' =>
                                        $data['class_code'],

                                        'name' =>
                                        $data['name'],

                                        'start_date' =>
                                        (string)
                                        $data['start_date'],

                                        'end_date' =>
                                        (string)
                                        $data['end_date'],

                                        'capacity' =>
                                        (int)
                                        $data['capacity'],

                                        'delivery_mode' =>
                                        $data['delivery_mode'],
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
                            QueryException) {
                            $this
                                ->creationFailure(
                                    'Database constraints rejected the Course Class. Check Class Code uniqueness and the selected resources.'
                                );

                            return;
                        } catch (
                            Throwable) {
                            $this
                                ->creationFailure(
                                    'An unexpected error occurred while creating the Course Class.'
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Course Class created'
                            )
                            ->body(
                                $courseClass
                                    ->class_code
                                    . ' · '
                                    . $courseClass
                                    ->name
                                    . ' was created as Planned.'
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
                'Course Class could not be created'
            )
            ->body($message)
            ->danger()
            ->send();
    }
}
