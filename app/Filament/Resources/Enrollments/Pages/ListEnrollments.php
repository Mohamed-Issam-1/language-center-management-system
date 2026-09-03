<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\CourseClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Enrollment\EnrollmentManagementService;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;

class ListEnrollments extends ListRecords
{
    protected static string $resource =
    EnrollmentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'enrollStudent'
            )
                ->label(
                    'Enroll Student'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    EnrollmentResource
                        ::canViewAny()
                )
                ->modalHeading(
                    'Enroll Student'
                )
                ->modalDescription(
                    'Select an eligible Student and Course Class inside your authorized operational scope.'
                )
                ->modalSubmitActionLabel(
                    'Create Enrollment'
                )
                ->schema([
                    Select::make(
                        'student_id'
                    )
                        ->label('Student')
                        ->options(
                            fn(): array =>
                            $this
                                ->studentOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

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

                    TextInput::make(
                        'enrollment_number'
                    )
                        ->label(
                            'Enrollment Number'
                        )
                        ->required()
                        ->maxLength(50),

                    DatePicker::make(
                        'enrollment_date'
                    )
                        ->label(
                            'Enrollment Date'
                        )
                        ->default(
                            now()
                                ->toDateString()
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
                                ->enrollmentFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        try {
                            $student =
                                $this
                                ->resolveStudent(
                                    (int) $data['student_id'],
                                    $actor
                                );

                            $courseClass =
                                $this
                                ->resolveCourseClass(
                                    (int) $data['course_class_id'],
                                    $actor
                                );

                            $enrollment =
                                app(
                                    EnrollmentManagementService::class
                                )->enroll(
                                    $actor,
                                    $student,
                                    $courseClass,
                                    [
                                        'enrollment_number' =>
                                        (string) $data['enrollment_number'],

                                        'enrollment_date' =>
                                        (string) $data['enrollment_date'],
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
                                ->enrollmentFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Student enrolled'
                            )
                            ->body(
                                'Enrollment '
                                    . $enrollment
                                    ->enrollment_number
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
    private function studentOptions(): array
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
            Student::withoutGlobalScopes()
            ->with([
                'person',
                'branch',
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                StudentStatus::Active
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
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                function (
                    Student $student
                ): array {
                    $studentName =
                        $student
                        ->person
                        ?->full_name
                        ?? 'Student #'
                        . $student->id;

                    $branchName =
                        $student
                        ->branch
                        ?->name
                        ?? 'Unknown Branch';

                    return [
                        $student->id =>
                        $studentName
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
            ->whereIn(
                'class_status',
                [
                    CourseClassStatus::Planned
                        ->value,

                    CourseClassStatus::Active
                        ->value,
                ]
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

    private function resolveStudent(
        int $studentId,
        User $actor
    ): Student {
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
            Student::withoutGlobalScopes()
            ->whereKey(
                $studentId
            )
            ->where(
                'center_id',
                $centerId
            );

        $this
            ->applyRequiredActorBranchScope(
                $query,
                $actor
            );

        return $query
            ->firstOrFail();
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
            );

        $this
            ->applyRequiredActorBranchScope(
                $query,
                $actor
            );

        return $query
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
                    'Center Owner Enrollment operations require center-wide Branch context.'
                );
            }

            return;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            throw new AuthorizationException(
                'The account cannot manage Enrollments.'
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

    private function enrollmentFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Enrollment could not be created'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}