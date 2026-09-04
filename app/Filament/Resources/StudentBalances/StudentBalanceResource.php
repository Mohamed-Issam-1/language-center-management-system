<?php

namespace App\Filament\Resources\StudentBalances;

use App\Filament\Resources\StudentBalances\Pages\ListStudentBalances;
use App\Filament\Resources\StudentBalances\Pages\ViewStudentBalance;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\Finance\FinanceReadService;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class StudentBalanceResource extends Resource
{
    protected static ?string $model =
    Student::class;

    protected static ?string $navigationLabel =
    'Student Balances';

    protected static ?string $modelLabel =
    'Student Balance';

    protected static ?string $pluralModelLabel =
    'Student Balances';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Student'
                )
                    ->schema([
                        TextEntry::make(
                            'person.full_name'
                        )
                            ->label(
                                'Student'
                            ),

                        TextEntry::make(
                            'person.national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'person.email'
                        )
                            ->label(
                                'Email'
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'person.phone_number'
                        )
                            ->label(
                                'Phone Number'
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label(
                                'Current Operational Branch'
                            )
                            ->placeholder(
                                'No current Branch'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Financial Summary'
                )
                    ->description(
                        'Totals include Active Fees and Posted Payment Allocations only. Reversed Payments and Voided Fees do not affect these balances.'
                    )
                    ->schema([
                        RepeatableEntry::make(
                            'financial_totals'
                        )
                            ->label('')
                            ->state(
                                fn(
                                    Student $record
                                ): array =>
                                static::financialTotalsState(
                                    $record
                                )
                            )
                            ->schema([
                                TextEntry::make(
                                    'currency_code'
                                )
                                    ->label(
                                        'Currency'
                                    )
                                    ->badge(),

                                TextEntry::make(
                                    'obligation'
                                )
                                    ->label(
                                        'Required'
                                    ),

                                TextEntry::make(
                                    'paid'
                                )
                                    ->label(
                                        'Paid'
                                    ),

                                TextEntry::make(
                                    'balance'
                                )
                                    ->label(
                                        'Remaining'
                                    ),
                            ])
                            ->columns(4),
                    ]),

                Section::make(
                    'Installments'
                )
                    ->description(
                        'Installments are shown according to their historical financial Branch, which may differ from the Student current operational Branch.'
                    )
                    ->schema([
                        RepeatableEntry::make(
                            'financial_installments'
                        )
                            ->label('')
                            ->state(
                                fn(
                                    Student $record
                                ): array =>
                                static::financialInstallmentsState(
                                    $record
                                )
                            )
                            ->schema([
                                TextEntry::make(
                                    'enrollment_number'
                                )
                                    ->label(
                                        'Enrollment'
                                    ),

                                TextEntry::make(
                                    'branch_name'
                                )
                                    ->label(
                                        'Financial Branch'
                                    ),

                                TextEntry::make(
                                    'sequence_number'
                                )
                                    ->label(
                                        'Installment'
                                    ),

                                TextEntry::make(
                                    'due_date'
                                )
                                    ->label(
                                        'Due Date'
                                    ),

                                TextEntry::make(
                                    'currency_code'
                                )
                                    ->label(
                                        'Currency'
                                    ),

                                TextEntry::make(
                                    'amount'
                                )
                                    ->label(
                                        'Amount'
                                    ),

                                TextEntry::make(
                                    'paid'
                                )
                                    ->label(
                                        'Paid'
                                    ),

                                TextEntry::make(
                                    'balance'
                                )
                                    ->label(
                                        'Remaining'
                                    ),

                                TextEntry::make(
                                    'status'
                                )
                                    ->label(
                                        'Status'
                                    )
                                    ->badge()
                                    ->formatStateUsing(
                                        fn(
                                            mixed $state
                                        ): string =>
                                        match ((string) $state) {
                                            'paid' =>
                                            'Paid',

                                            'overdue' =>
                                            'Overdue',

                                            'outstanding' =>
                                            'Outstanding',

                                            default =>
                                            ucfirst(
                                                (string) $state
                                            ),
                                        }
                                    ),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'person.full_name'
                )
                    ->label(
                        'Student'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'person.national_id_number'
                )
                    ->label(
                        'National ID Number'
                    )
                    ->searchable(),

                TextColumn::make(
                    'branch.name'
                )
                    ->label(
                        'Current Branch'
                    ),

                TextColumn::make(
                    'person.phone_number'
                )
                    ->label(
                        'Phone Number'
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(
                        'View Balance'
                    ),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Student::withoutGlobalScopes()
            ->with([
                'person',
                'branch',
            ]);

        if (
            ! static::actorCanAccessResource()
        ) {
            return static::denyQuery(
                $query
            );
        }

        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return static::denyQuery(
                $query
            );
        }

        $tenant =
            app(
                TenantContext::class
            );

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
        ) {
            return static::denyQuery(
                $query
            );
        }

        $branchId = null;

        if (
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            $branchContext =
                app(
                    BranchContext::class
                );

            if (
                ! $branchContext
                    ->isBranchScoped()
            ) {
                return static::denyQuery(
                    $query
                );
            }

            $branchId =
                $branchContext
                ->branchId();

            if ($branchId === null) {
                return static::denyQuery(
                    $query
                );
            }
        }

        return $query
            ->where(
                'students.center_id',
                $centerId
            )
            /*
             * Do NOT scope through Student.branch_id.
             *
             * A Student may have moved since the
             * Enrollment was created.
             *
             * Financial visibility follows:
             *
             * Student
             * -> Enrollment
             * -> EnrollmentFee
             * -> historical financial Branch.
             */
            ->whereExists(
                function (
                    $subQuery
                ) use (
                    $centerId,
                    $branchId
                ): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from(
                            'enrollments'
                        )
                        ->join(
                            'enrollment_fees',
                            function (
                                $join
                            ): void {
                                $join
                                    ->on(
                                        'enrollment_fees.enrollment_id',
                                        '=',
                                        'enrollments.id'
                                    )
                                    ->on(
                                        'enrollment_fees.center_id',
                                        '=',
                                        'enrollments.center_id'
                                    );
                            }
                        )
                        ->whereColumn(
                            'enrollments.student_id',
                            'students.id'
                        )
                        ->whereColumn(
                            'enrollments.center_id',
                            'students.center_id'
                        )
                        ->where(
                            'enrollment_fees.center_id',
                            $centerId
                        )
                        ->where(
                            'enrollment_fees.status',
                            EnrollmentFeeStatus
                            ::Active
                                ->value
                        );

                    if ($branchId !== null) {
                        $subQuery
                            ->where(
                                'enrollment_fees.branch_id',
                                $branchId
                            );
                    }
                }
            );
    }

    public static function canViewAny(): bool
    {
        return static::actorCanAccessResource();
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record instanceof Student
        ) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(
        Model $record
    ): bool {
        return false;
    }

    public static function canDelete(
        Model $record
    ): bool {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [];
    }

    /**
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' =>
            ListStudentBalances::route(
                '/'
            ),

            'view' =>
            ViewStudentBalance::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int, array{
     *     currency_code: string,
     *     obligation: string,
     *     paid: string,
     *     balance: string
     * }>
     */
    private static function financialTotalsState(
        Student $record
    ): array {
        $balance =
            static::financialSnapshot(
                $record
            );

        $rows = [];

        foreach (
            $balance['totals']
                ?? [] as $currency =>
            $values
        ) {
            $rows[] = [
                'currency_code' =>
                (string) $currency,

                'obligation' =>
                (string) (
                    $values['obligation']
                    ?? '0.00'
                ),

                'paid' =>
                (string) (
                    $values['paid']
                    ?? '0.00'
                ),

                'balance' =>
                (string) (
                    $values['balance']
                    ?? '0.00'
                ),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function financialInstallmentsState(
        Student $record
    ): array {
        $balance =
            static::financialSnapshot(
                $record
            );

        $installments =
            $balance['installments']
            ?? [];

        return is_array(
            $installments
        )
            ? array_values(
                $installments
            )
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function financialSnapshot(
        Student $record
    ): array {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            throw new AuthorizationException(
                'The authenticated User Account could not be resolved.'
            );
        }

        /*
     * Re-resolve the Student through this Resource's
     * authorized financial listing scope.
     *
     * Never trust only the model instance received
     * by the Infolist.
     */
        $student =
            static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'The Student is outside the authorized financial scope.'
            );
        }

        $balance =
            app(
                FinanceReadService::class
            )->studentBalance(
                $actor,
                $student
            );

        if (
            (int) (
                $balance['student_id']
                ?? 0
            ) !== (int) $student->id
        ) {
            throw new LogicException(
                'Financial summary Student identity mismatch.'
            );
        }

        return $balance;
    }

    private static function actorCanAccessResource(): bool
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
                ::ViewFinancialData
            )
        ) {
            return false;
        }

        if (
            ! in_array(
                $actor->systemRole(),
                [
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                    SystemRole::FinanceEmployee,
                ],
                true
            )
        ) {
            return false;
        }

        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant
                ->isCenterScoped()
        ) {
            return false;
        }

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $actor->center_id
            !== $centerId
        ) {
            return false;
        }

        $branchContext =
            app(
                BranchContext::class
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $branchContext
                ->isCenterWide();
        }

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

        return match ($actor->systemRole()) {
            SystemRole::BranchManager =>
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
                ->exists(),

            SystemRole::FinanceEmployee =>
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
                ->exists(),

            default =>
            false,
        };
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query
            ->whereRaw(
                '1 = 0'
            );
    }
}