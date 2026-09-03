<?php

namespace App\Filament\Resources\ClassSchedules\Pages;

use App\Filament\Resources\ClassSchedules\ClassScheduleResource;
use App\Models\Classroom;
use App\Models\CourseClass;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Scheduling\ScheduleManagementService;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;

class ListClassSchedules extends ListRecords
{
    protected static string $resource =
    ClassScheduleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createSchedule'
            )
                ->label(
                    'Create Schedule'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    ClassScheduleResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Create Class Schedule'
                )
                ->modalDescription(
                    'Create a recurring Schedule for an Active Course Class inside the authorized operational scope.'
                )
                ->modalSubmitActionLabel(
                    'Create Schedule'
                )
                ->schema([
                    Select::make(
                        'course_class_id'
                    )
                        ->label(
                            'Course Class'
                        )
                        ->options(
                            fn(): array =>
                            $this
                                ->courseClassOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make(
                        'classroom_id'
                    )
                        ->label(
                            'Classroom'
                        )
                        ->options(
                            fn(): array =>
                            $this
                                ->classroomOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make(
                        'teacher_id'
                    )
                        ->label(
                            'Teacher'
                        )
                        ->options(
                            fn(): array =>
                            $this
                                ->teacherOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make(
                        'day_of_week'
                    )
                        ->label(
                            'Day of Week'
                        )
                        ->options([
                            1 => 'Monday',
                            2 => 'Tuesday',
                            3 => 'Wednesday',
                            4 => 'Thursday',
                            5 => 'Friday',
                            6 => 'Saturday',
                            7 => 'Sunday',
                        ])
                        ->required(),

                    TimePicker::make(
                        'start_time'
                    )
                        ->label(
                            'Start Time'
                        )
                        ->seconds(false)
                        ->required(),

                    TimePicker::make(
                        'end_time'
                    )
                        ->label(
                            'End Time'
                        )
                        ->seconds(false)
                        ->required(),

                    DatePicker::make(
                        'effective_from'
                    )
                        ->label(
                            'Effective From'
                        )
                        ->required(),

                    DatePicker::make(
                        'effective_until'
                    )
                        ->label(
                            'Effective Until'
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
                            ! $actor
                                instanceof User
                        ) {
                            $this
                                ->scheduleFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        try {
                            $courseClass =
                                $this
                                ->resolveCourseClass(
                                    (int) $data['course_class_id'],
                                    $actor
                                );

                            $classroom =
                                $this
                                ->resolveClassroom(
                                    (int) $data['classroom_id'],
                                    $actor
                                );

                            $teacher =
                                $this
                                ->resolveTeacher(
                                    (int) $data['teacher_id'],
                                    $actor
                                );

                            if (
                                $classroom->branch_id
                                !== $courseClass
                                ->branch_id
                            ) {
                                throw new DomainException(
                                    'The selected Classroom must belong to the Course Class Branch.'
                                );
                            }

                            $schedule =
                                app(
                                    ScheduleManagementService::class
                                )->create(
                                    $actor,
                                    $courseClass,
                                    $classroom,
                                    $teacher,
                                    [
                                        'day_of_week' =>
                                        (int) $data['day_of_week'],

                                        'start_time' =>
                                        (string) $data['start_time'],

                                        'end_time' =>
                                        (string) $data['end_time'],

                                        'effective_from' =>
                                        (string) $data['effective_from'],

                                        'effective_until' =>
                                        (string) $data['effective_until'],
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
                                ->scheduleFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Schedule created'
                            )
                            ->body(
                                'Class Schedule #'
                                    . $schedule->id
                                    . ' was created successfully.'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private function courseClassOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            return [];
        }

        $query =
            CourseClass::withoutGlobalScopes()
            ->with('branch')
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'class_status',
                CourseClassStatus::Active
                    ->value
            );

        if (
            ! $this
                ->applyActorBranchScope(
                    $query,
                    $actor
                )
        ) {
            return [];
        }

        return $query
            ->orderBy('class_code')
            ->get()
            ->mapWithKeys(
                function (
                    CourseClass $courseClass
                ): array {
                    $branchName =
                        $courseClass
                        ->branch
                        ?->name
                        ?? 'Unknown Branch';

                    return [
                        $courseClass->id =>
                        $courseClass
                            ->class_code
                            . ' — '
                            . $courseClass
                            ->name
                            . ' — '
                            . $branchName,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function classroomOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            return [];
        }

        $query =
            Classroom::withoutGlobalScopes()
            ->with('branch')
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                ClassroomStatus::Active
                    ->value
            )
            ->where(
                'availability_status',
                ClassroomAvailabilityStatus::Available
                    ->value
            );

        if (
            ! $this
                ->applyActorBranchScope(
                    $query,
                    $actor
                )
        ) {
            return [];
        }

        return $query
            ->orderBy('code')
            ->get()
            ->mapWithKeys(
                function (
                    Classroom $classroom
                ): array {
                    $branchName =
                        $classroom
                        ->branch
                        ?->name
                        ?? 'Unknown Branch';

                    return [
                        $classroom->id =>
                        $classroom->code
                            . ' — '
                            . $classroom->name
                            . ' — '
                            . $branchName,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function teacherOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            return [];
        }

        return Teacher::withoutGlobalScopes()
            ->with('person')
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                function (
                    Teacher $teacher
                ): array {
                    return [
                        $teacher->id =>
                        $teacher
                            ->person
                            ?->full_name
                            ?? 'Teacher #'
                            . $teacher->id,
                    ];
                }
            )
            ->all();
    }

    private function resolveCourseClass(
        int $courseClassId,
        User $actor
    ): CourseClass {
        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        $query =
            CourseClass::withoutGlobalScopes()
            ->whereKey(
                $courseClassId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'class_status',
                CourseClassStatus::Active
                    ->value
            );

        $this
            ->applyRequiredActorBranchScope(
                $query,
                $actor
            );

        return $query
            ->firstOrFail();
    }

    private function resolveClassroom(
        int $classroomId,
        User $actor
    ): Classroom {
        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        $query =
            Classroom::withoutGlobalScopes()
            ->whereKey(
                $classroomId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                ClassroomStatus::Active
                    ->value
            )
            ->where(
                'availability_status',
                ClassroomAvailabilityStatus::Available
                    ->value
            );

        $this
            ->applyRequiredActorBranchScope(
                $query,
                $actor
            );

        return $query
            ->firstOrFail();
    }

    private function resolveTeacher(
        int $teacherId,
        User $actor
    ): Teacher {
        $centerId =
            $this
            ->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        return Teacher::withoutGlobalScopes()
            ->whereKey(
                $teacherId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                StaffStatus::Active
                    ->value
            )
            ->firstOrFail();
    }

    private function authorizedCenterId(
        User $actor
    ): ?int {
        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant
                ->isCenterScoped()
        ) {
            return null;
        }

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
        ) {
            return null;
        }

        return $centerId;
    }

    private function applyActorBranchScope(
        Builder $query,
        User $actor
    ): bool {
        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return app(
                BranchContext::class
            )->isCenterWide();
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return false;
        }

        $branchContext =
            app(BranchContext::class);

        if (
            ! $branchContext
                ->isBranchScoped()
        ) {
            return false;
        }

        $branchId =
            $branchContext
            ->branchId();

        if ($branchId === null) {
            return false;
        }

        if (
            ! $actor
                ->activeBranchManagerAssignment()
                ->where(
                    'center_id',
                    $actor->center_id
                )
                ->where(
                    'branch_id',
                    $branchId
                )
                ->exists()
        ) {
            return false;
        }

        $query->where(
            'branch_id',
            $branchId
        );

        return true;
    }

    private function applyRequiredActorBranchScope(
        Builder $query,
        User $actor
    ): void {
        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! app(
                    BranchContext::class
                )->isCenterWide()
            ) {
                throw new AuthorizationException(
                    'Center Owner Scheduling operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Class Schedules.'
            );
        }

        $branchContext =
            app(BranchContext::class);

        $branchId =
            $branchContext
            ->branchId();

        if (
            ! $branchContext
                ->isBranchScoped()
            || $branchId === null
        ) {
            throw new AuthorizationException(
                'Branch operational context has not been established.'
            );
        }

        if (
            ! $actor
                ->activeBranchManagerAssignment()
                ->where(
                    'center_id',
                    $actor->center_id
                )
                ->where(
                    'branch_id',
                    $branchId
                )
                ->exists()
        ) {
            throw new AuthorizationException(
                'The Branch Manager does not have an active assignment for the current Branch.'
            );
        }

        $query->where(
            'branch_id',
            $branchId
        );
    }

    private function scheduleFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Schedule could not be created'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}