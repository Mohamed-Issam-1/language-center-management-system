<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\AttendanceStatus;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Attendance\AttendanceManagementService;
use App\Support\Enums\ClassSessionStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;

class ListAttendances extends ListRecords
{
    protected static string $resource =
    AttendanceResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'recordAttendance'
            )
                ->label(
                    'Record Attendance'
                )
                ->color(
                    'primary'
                )
                ->visible(
                    fn(): bool =>
                    $this->actorCanRecordAttendance()
                )
                ->modalHeading(
                    'Record Attendance'
                )
                ->modalDescription(
                    'Record Attendance for an Enrollment and concrete Class Session inside the assigned Branch.'
                )
                ->modalSubmitActionLabel(
                    'Record Attendance'
                )
                ->schema([
                    Select::make(
                        'session_id'
                    )
                        ->label(
                            'Class Session'
                        )
                        ->options(
                            fn(): array =>
                            $this->sessionOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    /*
                     * The selected Enrollment is re-resolved
                     * against the selected Session before the
                     * domain service is called.
                     *
                     * The service remains responsible for all
                     * lifecycle and historical eligibility rules.
                     */
                    Select::make(
                        'enrollment_id'
                    )
                        ->label(
                            'Enrollment'
                        )
                        ->options(
                            fn(): array =>
                            $this->enrollmentOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make(
                        'attendance_status_id'
                    )
                        ->label(
                            'Attendance Status'
                        )
                        ->options(
                            fn(): array =>
                            $this->attendanceStatusOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make(
                        'late_minutes'
                    )
                        ->label(
                            'Late Minutes'
                        )
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    TextInput::make(
                        'excuse'
                    )
                        ->label(
                            'Excuse'
                        )
                        ->maxLength(255),

                    Textarea::make(
                        'notes'
                    )
                        ->label(
                            'Notes'
                        )
                        ->rows(3),
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
                            $this->attendanceFailure(
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        validator(
                            [
                                'late_minutes' =>
                                $data['late_minutes']
                                    ?? null,
                            ],
                            [
                                'late_minutes' => [
                                    'required',
                                    'integer',
                                    'min:0',
                                ],
                            ]
                        )->validate();

                        try {
                            $session =
                                $this->resolveSession(
                                    (int) $data['session_id'],
                                    $actor
                                );

                            $enrollment =
                                $this->resolveEnrollment(
                                    (int) $data['enrollment_id'],
                                    $session,
                                    $actor
                                );

                            $status =
                                $this->resolveAttendanceStatus(
                                    (int) $data['attendance_status_id'],
                                    $actor
                                );

                            $attendance =
                                app(
                                    AttendanceManagementService::class
                                )->record(
                                    $actor,
                                    $session,
                                    $enrollment,
                                    [
                                        'attendance_status_id' =>
                                        $status->id,

                                        'late_minutes' =>
                                        (int) (
                                            $data['late_minutes']
                                            ?? 0
                                        ),
                                        'excuse' =>
                                        $data['excuse']
                                            ?? null,

                                        'notes' =>
                                        $data['notes']
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
                            $this->attendanceFailure(
                                $exception->getMessage()
                            );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Attendance recorded'
                            )
                            ->body(
                                'Attendance record #'
                                    . $attendance->id
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
    private function sessionOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branchId =
            $this->authorizedBranchId(
                $actor
            );

        if (
            $centerId === null
            || $branchId === null
        ) {
            return [];
        }

        return ClassSession::withoutGlobalScopes()
            ->with(
                'courseClass'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'session_status',
                [
                    ClassSessionStatus::Scheduled
                        ->value,

                    ClassSessionStatus::Completed
                        ->value,
                ]
            )
            ->whereHas(
                'courseClass',
                function (
                    Builder $query
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'branch_id',
                            $branchId
                        );
                }
            )
            ->orderByDesc(
                'session_date'
            )
            ->orderBy(
                'start_time'
            )
            ->get()
            ->mapWithKeys(
                function (
                    ClassSession $session
                ): array {
                    $courseClass =
                        $session
                        ->courseClass;

                    $classLabel =
                        $courseClass
                        ?->class_code
                        ?? 'Class #'
                        . $session->class_id;

                    $date =
                        $session
                        ->session_date
                        ?->format(
                            'Y-m-d'
                        )
                        ?? 'Unknown Date';

                    return [
                        $session->id =>
                        $classLabel
                            . ' — '
                            . $date
                            . ' — '
                            . $session->start_time,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function enrollmentOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        $branchId =
            $this->authorizedBranchId(
                $actor
            );

        if (
            $centerId === null
            || $branchId === null
        ) {
            return [];
        }

        /*
         * Historical Withdrawn / Transferred Enrollments are
         * intentionally not removed here.
         *
         * AttendanceManagementService owns the date-sensitive
         * historical eligibility rules.
         */
        return Enrollment::withoutGlobalScopes()
            ->with([
                'student.person',
                'courseClass',
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'enrollment_status',
                '!=',
                EnrollmentStatus::Cancelled
                    ->value
            )
            ->whereHas(
                'courseClass',
                function (
                    Builder $query
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'branch_id',
                            $branchId
                        );
                }
            )
            ->orderBy(
                'enrollment_number'
            )
            ->get()
            ->mapWithKeys(
                function (
                    Enrollment $enrollment
                ): array {
                    $studentName =
                        $enrollment
                        ->student
                        ?->person
                        ?->full_name
                        ?? 'Student #'
                        . $enrollment->student_id;

                    $classCode =
                        $enrollment
                        ->courseClass
                        ?->class_code
                        ?? 'Class #'
                        . $enrollment->class_id;

                    return [
                        $enrollment->id =>
                        $enrollment
                            ->enrollment_number
                            . ' — '
                            . $studentName
                            . ' — '
                            . $classCode,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function attendanceStatusOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        if (
            $centerId === null
            || $this->authorizedBranchId(
                $actor
            ) === null
        ) {
            return [];
        }

        return AttendanceStatus::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'is_active',
                true
            )
            ->orderBy(
                'name'
            )
            ->get()
            ->mapWithKeys(
                function (
                    AttendanceStatus $status
                ): array {
                    return [
                        $status->id =>
                        $status->name
                            . ' ('
                            . $status->code
                            . ')',
                    ];
                }
            )
            ->all();
    }

    private function resolveSession(
        int $sessionId,
        User $actor
    ): ClassSession {
        $centerId =
            $this->requiredCenterId(
                $actor
            );

        $branchId =
            $this->requiredBranchId(
                $actor
            );

        return ClassSession::withoutGlobalScopes()
            ->whereKey(
                $sessionId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'session_status',
                [
                    ClassSessionStatus::Scheduled
                        ->value,

                    ClassSessionStatus::Completed
                        ->value,
                ]
            )
            ->whereHas(
                'courseClass',
                function (
                    Builder $query
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'branch_id',
                            $branchId
                        );
                }
            )
            ->firstOrFail();
    }

    private function resolveEnrollment(
        int $enrollmentId,
        ClassSession $session,
        User $actor
    ): Enrollment {
        $centerId =
            $this->requiredCenterId(
                $actor
            );

        $branchId =
            $this->requiredBranchId(
                $actor
            );

        return Enrollment::withoutGlobalScopes()
            ->whereKey(
                $enrollmentId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'class_id',
                $session->class_id
            )
            ->whereHas(
                'courseClass',
                function (
                    Builder $query
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $centerId
                        )
                        ->where(
                            'branch_id',
                            $branchId
                        );
                }
            )
            ->firstOrFail();
    }

    private function resolveAttendanceStatus(
        int $statusId,
        User $actor
    ): AttendanceStatus {
        $centerId =
            $this->requiredCenterId(
                $actor
            );

        $this->requiredBranchId(
            $actor
        );

        return AttendanceStatus::withoutGlobalScopes()
            ->whereKey(
                $statusId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'is_active',
                true
            )
            ->firstOrFail();
    }

    private function actorCanRecordAttendance(): bool
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageAttendance
            )
        ) {
            return false;
        }

        return $this->authorizedCenterId(
            $actor
        ) !== null
            && $this->authorizedBranchId(
                $actor
            ) !== null;
    }

    private function authorizedCenterId(
        User $actor
    ): ?int {
        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant->isCenterScoped()
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

    private function authorizedBranchId(
        User $actor
    ): ?int {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        if (
            $centerId === null
            || $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return null;
        }

        $branchContext =
            app(BranchContext::class);

        if (
            ! $branchContext
                ->isBranchScoped()
        ) {
            return null;
        }

        $branchId =
            $branchContext
            ->branchId();

        if ($branchId === null) {
            return null;
        }

        if (
            ! $actor
                ->activeBranchManagerAssignment()
                ->where(
                    'center_id',
                    $centerId
                )
                ->where(
                    'branch_id',
                    $branchId
                )
                ->exists()
        ) {
            return null;
        }

        return $branchId;
    }

    private function requiredCenterId(
        User $actor
    ): int {
        $centerId =
            $this->authorizedCenterId(
                $actor
            );

        if ($centerId === null) {
            throw new AuthorizationException(
                'The current Center scope is not authorized.'
            );
        }

        return $centerId;
    }

    private function requiredBranchId(
        User $actor
    ): int {
        $branchId =
            $this->authorizedBranchId(
                $actor
            );

        if ($branchId === null) {
            throw new AuthorizationException(
                'The Branch Manager does not have an active assignment for the current Branch.'
            );
        }

        return $branchId;
    }

    private function attendanceFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Attendance could not be recorded'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}