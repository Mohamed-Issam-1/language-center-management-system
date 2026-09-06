<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\EnrollmentFee;
use App\Models\FeeInstallment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceManagementService;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class ListPayments extends ListRecords
{
    protected static string $resource =
    PaymentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make(
                'recordPayment'
            )
                ->label(
                    'Record Payment'
                )
                ->color('primary')
                ->visible(
                    fn(): bool =>
                    $this->canRecordPayment()
                )
                ->modalHeading(
                    'Record Payment'
                )
                ->modalDescription(
                    'Record a Payment against one or more outstanding Fee Installments for the selected Student.'
                )
                ->modalSubmitActionLabel(
                    'Record Payment'
                )
                ->schema([
                    Hidden::make(
                        'idempotency_key'
                    )
                        ->default(
                            fn(): string =>
                            'filament-'
                                . Str::uuid()
                                ->toString()
                        ),

                    Select::make(
                        'branch_id'
                    )
                        ->label(
                            'Financial Branch'
                        )
                        ->options(
                            fn(): array =>
                            $this
                                ->branchOptions()
                        )
                        ->default(
                            fn(): ?int =>
                            $this
                                ->defaultBranchId()
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(
                            function (
                                Set $set
                            ): void {
                                $set(
                                    'student_id',
                                    null
                                );

                                $set(
                                    'allocations',
                                    []
                                );
                            }
                        )
                        ->required(),

                    Select::make(
                        'student_id'
                    )
                        ->label(
                            'Student'
                        )
                        ->options(
                            fn(
                                Get $get
                            ): array =>
                            $this
                                ->studentOptions(
                                    $this
                                        ->positiveIntOrNull(
                                            $get(
                                                'branch_id'
                                            )
                                        )
                                )
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(
                            function (
                                Set $set
                            ): void {
                                $set(
                                    'allocations',
                                    []
                                );
                            }
                        )
                        ->required(),

                    Repeater::make(
                        'allocations'
                    )
                        ->label(
                            'Payment Allocations'
                        )
                        ->schema([
                            Select::make(
                                'fee_installment_id'
                            )
                                ->label(
                                    'Outstanding Installment'
                                )
                                ->options(
                                    fn(
                                        Get $get
                                    ): array =>
                                    $this
                                        ->installmentOptions(
                                            $this
                                                ->positiveIntOrNull(
                                                    $get(
                                                        '../../branch_id'
                                                    )
                                                ),

                                            $this
                                                ->positiveIntOrNull(
                                                    $get(
                                                        '../../student_id'
                                                    )
                                                )
                                        )
                                )
                                ->searchable()
                                ->preload()
                                ->required(),

                            TextInput::make(
                                'amount'
                            )
                                ->label(
                                    'Allocation Amount'
                                )
                                ->numeric()
                                ->minValue(
                                    0.01
                                )
                                ->required(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel(
                            'Add Allocation'
                        )
                        ->reorderable(false),

                    TextInput::make(
                        'amount'
                    )
                        ->label(
                            'Total Payment Amount'
                        )
                        ->numeric()
                        ->minValue(
                            0.01
                        )
                        ->required(),

                    TextInput::make(
                        'payment_method'
                    )
                        ->label(
                            'Payment Method'
                        )
                        ->placeholder(
                            'e.g. cash'
                        )
                        ->maxLength(50)
                        ->required(),

                    DateTimePicker::make(
                        'paid_at'
                    )
                        ->label(
                            'Paid At'
                        )
                        ->default(
                            now()
                        )
                        ->seconds(true)
                        ->required(),

                    TextInput::make(
                        'reference'
                    )
                        ->label(
                            'Reference'
                        )
                        ->maxLength(100),

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
                            ! $actor
                                instanceof User
                        ) {
                            $this
                                ->paymentFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                            return;
                        }

                        try {
                            $branch =
                                $this
                                ->resolveBranch(
                                    (int) (
                                        $data['branch_id']
                                        ?? 0
                                    ),
                                    $actor
                                );

                            $student =
                                $this
                                ->resolveStudent(
                                    (int) (
                                        $data['student_id']
                                        ?? 0
                                    ),
                                    $branch,
                                    $actor
                                );

                            $payment =
                                app(
                                    FinanceManagementService::class
                                )->postPayment(
                                    $actor,
                                    $student,
                                    $branch,
                                    [
                                        'idempotency_key' =>
                                        (string) (
                                            $data['idempotency_key']
                                            ?? (
                                                'filament-'
                                                . Str::uuid()
                                                ->toString()
                                            )
                                        ),

                                        'amount' =>
                                        $data['amount']
                                            ?? null,

                                        'payment_method' =>
                                        $data['payment_method']
                                            ?? null,

                                        'paid_at' =>
                                        $data['paid_at']
                                            ?? null,

                                        'reference' =>
                                        $data['reference']
                                            ?? null,

                                        'notes' =>
                                        $data['notes']
                                            ?? null,

                                        'allocations' =>
                                        $data['allocations']
                                            ?? [],
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
                                ->paymentFailure(
                                    $exception
                                        ->getMessage()
                                );

                            return;
                        }

                        Notification::make()
                            ->title(
                                'Payment recorded'
                            )
                            ->body(
                                'Receipt '
                                    . $payment
                                    ->receipt_number
                                    . ' was created successfully.'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    private function canRecordPayment(): bool
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

        return PaymentResource
            ::canViewAny();
    }

    /**
     * @return array<int|string, string>
     */
    private function branchOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        $ids =
            $this
            ->authorizedBranchIds(
                $actor
            );

        if ($ids === []) {
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

        return Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $ids
            )
            ->orderBy(
                'name'
            )
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    private function defaultBranchId(): ?int
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return null;
        }

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return null;
        }

        $ids =
            $this
            ->authorizedBranchIds(
                $actor
            );

        if (
            count($ids)
            !== 1
        ) {
            return null;
        }

        return (int) $ids[0];
    }

    /**
     * @return array<int|string, string>
     */
    private function studentOptions(
        ?int $branchId
    ): array {
        if ($branchId === null) {
            return [];
        }

        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        if (
            ! in_array(
                $branchId,
                $this
                    ->authorizedBranchIds(
                        $actor
                    ),
                true
            )
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

        /*
         * Student.current branch is deliberately NOT used.
         *
         * Financial history belongs to the Enrollment Fee's
         * historical Branch. A Student may have moved after
         * the original Enrollment.
         */
        $enrollmentIds =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->where(
                'status',
                EnrollmentFeeStatus
                ::Active
                    ->value
            )
            ->pluck(
                'enrollment_id'
            );

        if ($enrollmentIds->isEmpty()) {
            return [];
        }

        $studentIds =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $enrollmentIds
            )
            ->pluck(
                'student_id'
            )
            ->unique()
            ->values();

        return Student::withoutGlobalScopes()
            ->with(
                'person'
            )
            ->where(
                'center_id',
                $centerId
            )
            ->whereIn(
                'id',
                $studentIds
            )
            ->orderBy(
                'id'
            )
            ->get()
            ->mapWithKeys(
                function (
                    Student $student
                ): array {
                    $name =
                        $student
                        ->person
                        ?->full_name
                        ?? 'Student #'
                        . $student->id;

                    return [
                        $student->id =>
                        $name
                            . ' | Student #'
                            . $student->id,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private function installmentOptions(
        ?int $branchId,
        ?int $studentId
    ): array {
        if (
            $branchId === null
            || $studentId === null
        ) {
            return [];
        }

        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return [];
        }

        if (
            ! in_array(
                $branchId,
                $this
                    ->authorizedBranchIds(
                        $actor
                    ),
                true
            )
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

        $enrollmentIds =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $studentId
            )
            ->pluck(
                'id'
            );

        if ($enrollmentIds->isEmpty()) {
            return [];
        }

        $feeIds =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->where(
                'status',
                EnrollmentFeeStatus
                ::Active
                    ->value
            )
            ->whereIn(
                'enrollment_id',
                $enrollmentIds
            )
            ->pluck(
                'id'
            );

        if ($feeIds->isEmpty()) {
            return [];
        }

        return FeeInstallment
            ::withoutGlobalScopes()
            ->with([
                'enrollmentFee.enrollment',
            ])
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->whereIn(
                'enrollment_fee_id',
                $feeIds
            )
            ->orderBy(
                'due_date'
            )
            ->orderBy(
                'sequence_number'
            )
            ->get()
            ->mapWithKeys(
                function (
                    FeeInstallment $installment
                ) use (
                    $centerId,
                    $branchId
                ): array {
                    $allocated =
                        PaymentAllocation
                        ::withoutGlobalScopes()
                        ->join(
                            'payments',
                            'payments.id',
                            '=',
                            'payment_allocations.payment_id'
                        )
                        ->where(
                            'payment_allocations.center_id',
                            $centerId
                        )
                        ->where(
                            'payment_allocations.branch_id',
                            $branchId
                        )
                        ->where(
                            'payment_allocations.fee_installment_id',
                            $installment->id
                        )
                        ->where(
                            'payments.center_id',
                            $centerId
                        )
                        ->where(
                            'payments.branch_id',
                            $branchId
                        )
                        ->where(
                            'payments.status',
                            PaymentStatus
                            ::Posted
                                ->value
                        )
                        ->sum(
                            'payment_allocations.amount'
                        );

                    $outstanding =
                        (float) $installment
                            ->amount
                        - (float) $allocated;

                    if (
                        $outstanding
                        <= 0
                    ) {
                        return [];
                    }

                    $fee =
                        $installment
                        ->enrollmentFee;

                    $enrollmentNumber =
                        $fee
                        ?->enrollment
                        ?->enrollment_number
                        ?? 'Unknown Enrollment';

                    $currency =
                        $fee
                        ?->currency_code
                        ?? '';

                    $dueDate =
                        $installment
                        ->due_date
                        ?->format(
                            'Y-m-d'
                        )
                        ?? 'No due date';

                    return [
                        $installment->id =>
                        $enrollmentNumber
                            . ' | Installment '
                            . $installment
                            ->sequence_number
                            . ' | Due '
                            . $dueDate
                            . ' | Outstanding '
                            . number_format(
                                $outstanding,
                                2,
                                '.',
                                ''
                            )
                            . ' '
                            . $currency,
                    ];
                }
            )
            ->all();
    }

    private function resolveBranch(
        int $branchId,
        User $actor
    ): Branch {
        if (
            ! in_array(
                $branchId,
                $this
                    ->authorizedBranchIds(
                        $actor
                    ),
                true
            )
        ) {
            throw new AuthorizationException(
                'The selected Branch is outside the authorized financial scope.'
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

        return Branch::withoutGlobalScopes()
            ->whereKey(
                $branchId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->firstOrFail();
    }

    private function resolveStudent(
        int $studentId,
        Branch $branch,
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

        $student =
            Student::withoutGlobalScopes()
            ->whereKey(
                $studentId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->firstOrFail();

        /*
         * Do not compare Student.branch_id with the financial
         * Branch. A Student may have moved after Enrollment.
         */
        $enrollmentIds =
            Enrollment::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'student_id',
                $student->id
            )
            ->pluck(
                'id'
            );

        $hasFinancialObligation =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->where(
                'status',
                EnrollmentFeeStatus
                ::Active
                    ->value
            )
            ->whereIn(
                'enrollment_id',
                $enrollmentIds
            )
            ->exists();

        if (! $hasFinancialObligation) {
            throw new AuthorizationException(
                'The Student has no active financial obligation in the selected Branch.'
            );
        }

        return $student;
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

    private function positiveIntOrNull(
        mixed $value
    ): ?int {
        if (
            ! is_numeric(
                $value
            )
        ) {
            return null;
        }

        $value =
            (int) $value;

        return $value > 0
            ? $value
            : null;
    }

    private function paymentFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Payment could not be recorded'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }
}