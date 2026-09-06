<?php

namespace App\Filament\Resources\EnrollmentFees\Pages;

use App\Filament\Resources\EnrollmentFees\EnrollmentFeeResource;
use App\Models\Branch;
use App\Models\CourseClass;
use App\Models\Enrollment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\User;
use App\Services\Finance\FinanceManagementService;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class ListEnrollmentFees extends ListRecords
{
    protected static string $resource =
        EnrollmentFeeResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'createFee'
            )
                ->label(
                    'Create Fee'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    $this->canCreateFee()
                )
                ->modalHeading(
                    'Create Enrollment Fee'
                )
                ->modalDescription(
                    'Create a financial Fee for an Enrollment. Leave the Fee Amount and Installments empty to use the Course default Fee with one Installment due on the Enrollment date.'
                )
                ->modalSubmitActionLabel(
                    'Create Fee'
                )
                ->schema([
                    Select::make(
                        'enrollment_id'
                    )
                        ->label(
                            'Enrollment'
                        )
                        ->options(
                            fn(): array =>
                            $this
                                ->enrollmentOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make(
                        'amount'
                    )
                        ->label(
                            'Fee Amount'
                        )
                        ->helperText(
                            'Optional. Leave empty to use the Course default Fee.'
                        )
                        ->numeric()
                        ->minValue(
                            0.01
                        ),

                    Repeater::make(
                        'installments'
                    )
                        ->label(
                            'Custom Installment Plan'
                        )
                        ->helperText(
                            'Optional. Leave empty to create one full-value Installment due on the Enrollment date.'
                        )
                        ->schema([
                            TextInput::make(
                                'amount'
                            )
                                ->label(
                                    'Installment Amount'
                                )
                                ->numeric()
                                ->minValue(
                                    0.01
                                )
                                ->required(),

                            DatePicker::make(
                                'due_date'
                            )
                                ->label(
                                    'Due Date'
                                )
                                ->required(),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel(
                            'Add Installment'
                        )
                        ->reorderable(false),
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
                                ->feeCreationFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        try {
                            $enrollment =
                                $this
                                    ->resolveEnrollment(
                                        (int) (
                                            $data[
                                                'enrollment_id'
                                            ]
                                            ?? 0
                                        ),
                                        $actor
                                    );

                            $attributes = [];

                            $amount =
                                $data['amount']
                                ?? null;

                            if (
                                $amount !== null
                                && (
                                    ! is_string(
                                        $amount
                                    )
                                    || trim(
                                        $amount
                                    ) !== ''
                                )
                            ) {
                                $attributes[
                                    'amount'
                                ] = $amount;
                            }

                            $installments =
                                $data[
                                    'installments'
                                ]
                                ?? [];

                            if (
                                is_array(
                                    $installments
                                )
                                && $installments !== []
                            ) {
                                $attributes[
                                    'installments'
                                ] =
                                    array_values(
                                        $installments
                                    );
                            }

                            $fee =
                                app(
                                    FinanceManagementService::class
                                )->createFee(
                                    $actor,
                                    $enrollment,
                                    $attributes
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
                                ->feeCreationFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Fee created'
                            )
                            ->body(
                                'Enrollment Fee #'
                                . $fee->id
                                . ' was created successfully.'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    private function canCreateFee(): bool
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission
                    ::ManageFinancialOperations
            )
        ) {
            return false;
        }

        return EnrollmentFeeResource
            ::canViewAny();
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
            $this
                ->authorizedCenterId(
                    $actor
                );

        if ($centerId === null) {
            return [];
        }

        $branchIds =
            $this
                ->authorizedBranchIds(
                    $actor
                );

        if ($branchIds === []) {
            return [];
        }

        /*
         * Direct database joins are intentional here.
         *
         * Student has a Branch global scope, but an
         * Enrollment Fee belongs to the historical
         * Enrollment / CourseClass Branch.
         *
         * A Student may therefore have moved to another
         * Branch after this Enrollment was created.
         */
        return DB::table(
            'enrollments'
        )
            ->join(
                'students',
                function (
                    $join
                ): void {
                    $join
                        ->on(
                            'students.id',
                            '=',
                            'enrollments.student_id'
                        )
                        ->on(
                            'students.center_id',
                            '=',
                            'enrollments.center_id'
                        );
                }
            )
            ->join(
                'people',
                function (
                    $join
                ): void {
                    $join
                        ->on(
                            'people.id',
                            '=',
                            'students.person_id'
                        )
                        ->on(
                            'people.center_id',
                            '=',
                            'enrollments.center_id'
                        );
                }
            )
            ->join(
                'course_classes',
                function (
                    $join
                ): void {
                    $join
                        ->on(
                            'course_classes.id',
                            '=',
                            'enrollments.class_id'
                        )
                        ->on(
                            'course_classes.center_id',
                            '=',
                            'enrollments.center_id'
                        );
                }
            )
            ->join(
                'branches',
                function (
                    $join
                ): void {
                    $join
                        ->on(
                            'branches.id',
                            '=',
                            'course_classes.branch_id'
                        )
                        ->on(
                            'branches.center_id',
                            '=',
                            'course_classes.center_id'
                        );
                }
            )
            ->join(
                'courses',
                function (
                    $join
                ): void {
                    $join
                        ->on(
                            'courses.id',
                            '=',
                            'course_classes.course_id'
                        )
                        ->on(
                            'courses.center_id',
                            '=',
                            'course_classes.center_id'
                        );
                }
            )
            ->where(
                'enrollments.center_id',
                $centerId
            )
            ->whereIn(
                'course_classes.branch_id',
                $branchIds
            )
            ->whereNotExists(
                function (
                    $query
                ): void {
                    $query
                        ->selectRaw('1')
                        ->from(
                            'enrollment_fees'
                        )
                        ->whereColumn(
                            'enrollment_fees.enrollment_id',
                            'enrollments.id'
                        )
                        ->whereColumn(
                            'enrollment_fees.center_id',
                            'enrollments.center_id'
                        );
                }
            )
            ->orderBy(
                'branches.name'
            )
            ->orderBy(
                'enrollments.enrollment_number'
            )
            ->get([
                'enrollments.id',
                'enrollments.enrollment_number',
                'people.full_name',
                'course_classes.class_code',
                'course_classes.name as class_name',
                'branches.name as branch_name',
                'courses.default_fee',
            ])
            ->mapWithKeys(
                function (
                    object $row
                ): array {
                    $label =
                        $row
                            ->enrollment_number
                        . ' | '
                        . $row->full_name
                        . ' | '
                        . $row->class_code
                        . ' - '
                        . $row->class_name
                        . ' | '
                        . $row->branch_name
                        . ' | Default Fee '
                        . $row->default_fee;

                    return [
                        (int) $row->id =>
                            $label,
                    ];
                }
            )
            ->all();
    }

    private function resolveEnrollment(
        int $enrollmentId,
        User $actor
    ): Enrollment {
        if ($enrollmentId <= 0) {
            throw new AuthorizationException(
                'The selected Enrollment is invalid.'
            );
        }

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

        $enrollment =
            Enrollment::withoutGlobalScopes()
                ->whereKey(
                    $enrollmentId
                )
                ->where(
                    'center_id',
                    $centerId
                )
                ->firstOrFail();

        $courseClass =
            CourseClass::withoutGlobalScopes()
                ->whereKey(
                    $enrollment
                        ->class_id
                )
                ->where(
                    'center_id',
                    $centerId
                )
                ->firstOrFail();

        if (
            ! in_array(
                (int) $courseClass
                    ->branch_id,
                $this
                    ->authorizedBranchIds(
                        $actor
                    ),
                true
            )
        ) {
            throw new AuthorizationException(
                'The selected Enrollment is outside the authorized financial Branch scope.'
            );
        }

        /*
         * Student.branch_id is deliberately not checked.
         *
         * Fee ownership follows:
         *
         * Enrollment -> CourseClass -> Branch.
         */
        return $enrollment;
    }

    /**
     * @return array<int>
     */
    private function authorizedBranchIds(
        User $actor
    ): array {
        $centerId =
            $this
                ->authorizedCenterId(
                    $actor
                );

        if ($centerId === null) {
            return [];
        }

        $branchContext =
            app(
                BranchContext::class
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            if (
                ! $branchContext
                    ->isCenterWide()
            ) {
                return [];
            }

            return Branch::withoutGlobalScopes()
                ->where(
                    'center_id',
                    $centerId
                )
                ->orderBy(
                    'id'
                )
                ->pluck(
                    'id'
                )
                ->map(
                    fn(
                        mixed $id
                    ): int =>
                    (int) $id
                )
                ->all();
        }

        if (
            ! $branchContext
                ->isBranchScoped()
        ) {
            return [];
        }

        $branchId =
            $branchContext
                ->branchId();

        if ($branchId === null) {
            return [];
        }

        if (
            $actor->systemRole()
            === SystemRole::BranchManager
        ) {
            $hasAssignment =
                $actor
                    ->activeBranchManagerAssignment()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
                    ->exists();

            return $hasAssignment
                ? [(int) $branchId]
                : [];
        }

        if (
            $actor->systemRole()
            === SystemRole::FinanceEmployee
        ) {
            $hasAssignment =
                FinanceEmployeeAssignment
                    ::withoutGlobalScopes()
                    ->active()
                    ->where(
                        'center_id',
                        $centerId
                    )
                    ->where(
                        'user_id',
                        $actor->id
                    )
                    ->where(
                        'branch_id',
                        $branchId
                    )
                    ->exists();

            return $hasAssignment
                ? [(int) $branchId]
                : [];
        }

        return [];
    }

    private function authorizedCenterId(
        User $actor
    ): ?int {
        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant
                ->isCenterScoped()
        ) {
            return null;
        }

        $centerId =
            $tenant
                ->centerId();

        if (
            $centerId === null
            || $actor->center_id
                !== $centerId
        ) {
            return null;
        }

        return $centerId;
    }

    private function feeCreationFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Fee could not be created'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}