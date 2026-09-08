<?php

namespace App\Filament\Resources\EnrollmentFees;

use App\Filament\Resources\EnrollmentFees\Pages\ListEnrollmentFees;
use App\Filament\Resources\EnrollmentFees\Pages\ViewEnrollmentFee;
use App\Models\EnrollmentFee;
use App\Models\FinanceEmployeeAssignment;
use App\Models\User;
use App\Support\Enums\EnrollmentFeeStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\Finance\FinanceManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Filament\Actions\ActionGroup;

class EnrollmentFeeResource extends Resource
{
    protected static ?string $model =
    EnrollmentFee::class;

    protected static ?string $navigationLabel =
    'Enrollment Fees';

    protected static ?string $modelLabel =
    'Enrollment Fee';

    protected static ?string $pluralModelLabel =
    'Enrollment Fees';

    protected static string | \UnitEnum | null $navigationGroup =
    'Finance';

    protected static ?int $navigationSort =
    10;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Fee'
                )
                    ->schema([
                        TextEntry::make(
                            'id'
                        )
                            ->label(
                                'Fee ID'
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
                                static::statusLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusColor(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'amount'
                        )
                            ->label(
                                'Fee Amount'
                            ),

                        TextEntry::make(
                            'currency_code'
                        )
                            ->label(
                                'Currency'
                            ),

                        TextEntry::make(
                            'createdBy.person.full_name'
                        )
                            ->label(
                                'Created By'
                            )
                            ->placeholder(
                                'Unknown User'
                            ),

                        TextEntry::make(
                            'created_at'
                        )
                            ->label(
                                'Created At'
                            )
                            ->dateTime(
                                'Y-m-d H:i:s'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Enrollment'
                )
                    ->schema([
                        TextEntry::make(
                            'enrollment.enrollment_number'
                        )
                            ->label(
                                'Enrollment Number'
                            ),

                        TextEntry::make(
                            'enrollment.student.person.full_name'
                        )
                            ->label(
                                'Student'
                            ),

                        TextEntry::make(
                            'enrollment.student.person.national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'enrollment.courseClass.class_code'
                        )
                            ->label(
                                'Class Code'
                            ),

                        TextEntry::make(
                            'enrollment.courseClass.name'
                        )
                            ->label(
                                'Class'
                            ),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label(
                                'Financial Branch'
                            ),

                        TextEntry::make(
                            'enrollment.enrollment_date'
                        )
                            ->label(
                                'Enrollment Date'
                            )
                            ->date(
                                'Y-m-d'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Installments'
                )
                    ->schema([
                        RepeatableEntry::make(
                            'installments'
                        )
                            ->label('')
                            ->schema([
                                TextEntry::make(
                                    'sequence_number'
                                )
                                    ->label(
                                        'Installment'
                                    ),

                                TextEntry::make(
                                    'amount'
                                )
                                    ->label(
                                        'Amount'
                                    ),

                                TextEntry::make(
                                    'due_date'
                                )
                                    ->label(
                                        'Due Date'
                                    )
                                    ->date(
                                        'Y-m-d'
                                    ),
                            ])
                            ->columns(3),
                    ]),

                Section::make(
                    'Void Details'
                )
                    ->visible(
                        fn(
                            ?EnrollmentFee $record
                        ): bool =>
                        $record !== null
                            && $record
                            ->isVoided()
                    )
                    ->schema([
                        TextEntry::make(
                            'voided_at'
                        )
                            ->label(
                                'Voided At'
                            )
                            ->dateTime(
                                'Y-m-d H:i:s'
                            ),

                        TextEntry::make(
                            'voidedBy.person.full_name'
                        )
                            ->label(
                                'Voided By'
                            )
                            ->placeholder(
                                'Unknown User'
                            ),

                        TextEntry::make(
                            'void_reason'
                        )
                            ->label(
                                'Void Reason'
                            )
                            ->placeholder(
                                'Not provided'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'enrollment.enrollment_number'
                )
                    ->label(
                        'Enrollment Number'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'enrollment.student.person.full_name'
                )
                    ->label(
                        'Student'
                    )
                    ->searchable(),

                TextColumn::make(
                    'enrollment.courseClass.class_code'
                )
                    ->label(
                        'Class Code'
                    )
                    ->searchable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'branch.name'
                )
                    ->label(
                        'Branch'
                    ),

                TextColumn::make(
                    'amount'
                )
                    ->label(
                        'Amount'
                    )
                    ->sortable(),

                TextColumn::make(
                    'currency_code'
                )
                    ->label(
                        'Currency'
                    ),

                TextColumn::make(
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
                        static::statusLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::statusColor(
                            $state
                        )
                    ),

                TextColumn::make(
                    'created_at'
                )
                    ->label(
                        'Created At'
                    )
                    ->dateTime(
                        'Y-m-d H:i'
                    )
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Status'
                    )
                    ->options(
                        collect(
                            EnrollmentFeeStatus::cases()
                        )
                            ->mapWithKeys(
                                fn(
                                    EnrollmentFeeStatus $status
                                ): array => [
                                    $status->value =>
                                    $status->label(),
                                ]
                            )
                            ->all()
                    ),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'voidFee'
                    )
                        ->label(
                            'Void Fee'
                        )
                        ->color('danger')
                        ->visible(
                            fn(
                                EnrollmentFee $record
                            ): bool =>
                            static::actorCanVoidFee(
                                $record
                            )
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Void Enrollment Fee'
                        )
                        ->modalDescription(
                            'This will void the Fee while preserving the Fee and Installment history. A Fee with Posted Payments cannot be voided until those Payments are reversed.'
                        )
                        ->modalSubmitActionLabel(
                            'Void Fee'
                        )
                        ->schema([
                            Textarea::make(
                                'reason'
                            )
                                ->label(
                                    'Void Reason'
                                )
                                ->required()
                                ->maxLength(255)
                                ->rows(3),
                        ])
                        ->action(
                            function (
                                EnrollmentFee $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor instanceof User
                                ) {
                                    static::feeVoidFailure(
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    $fee =
                                        static::resolveScopedFee(
                                            $record
                                        );

                                    $fee =
                                        app(
                                            FinanceManagementService::class
                                        )->voidFee(
                                            $actor,
                                            $fee,
                                            $data['reason']
                                                ?? null
                                        );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | InvalidArgumentException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::feeVoidFailure(
                                        $exception
                                            ->getMessage()
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Fee voided'
                                    )
                                    ->body(
                                        'Enrollment Fee #'
                                            . $fee->id
                                            . ' was voided successfully.'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),
                ]),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            EnrollmentFee
            ::withoutGlobalScopes()
            ->with([
                'branch',
                'createdBy.person',
                'voidedBy.person',
                'installments' =>
                fn(
                    $installmentQuery
                ) =>
                $installmentQuery
                    ->orderBy(
                        'sequence_number'
                    ),
                'enrollment.student.person',
                'enrollment.courseClass.branch',
                'enrollment.courseClass.course',
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

        $query
            ->where(
                'enrollment_fees.center_id',
                $centerId
            )
            /*
             * Fail closed if persisted financial Branch
             * no longer agrees with:
             *
             * Fee -> Enrollment -> CourseClass -> Branch.
             */
            ->whereExists(
                function (
                    $subQuery
                ): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from(
                            'enrollments'
                        )
                        ->join(
                            'course_classes',
                            'course_classes.id',
                            '=',
                            'enrollments.class_id'
                        )
                        ->whereColumn(
                            'enrollments.id',
                            'enrollment_fees.enrollment_id'
                        )
                        ->whereColumn(
                            'enrollments.center_id',
                            'enrollment_fees.center_id'
                        )
                        ->whereColumn(
                            'course_classes.center_id',
                            'enrollment_fees.center_id'
                        )
                        ->whereColumn(
                            'course_classes.branch_id',
                            'enrollment_fees.branch_id'
                        );
                }
            );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $query;
        }

        $branchContext =
            app(
                BranchContext::class
            );

        $branchId =
            $branchContext
            ->branchId();

        if (
            ! $branchContext
                ->isBranchScoped()
            || $branchId === null
        ) {
            return static::denyQuery(
                $query
            );
        }

        return $query->where(
            'enrollment_fees.branch_id',
            $branchId
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
            ! $record
                instanceof EnrollmentFee
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
        /*
         * Fee creation will be exposed through
         * a controlled Action that delegates to
         * FinanceManagementService.
         *
         * Native CRUD remains disabled.
         */
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
            ListEnrollmentFees::route(
                '/'
            ),

            'view' =>
            ViewEnrollmentFee::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanVoidFee(
        EnrollmentFee $record
    ): bool {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            ! $record->isActive()
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

        return static::canView(
            $record
        );
    }

    private static function resolveScopedFee(
        EnrollmentFee $record
    ): EnrollmentFee {
        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->firstOrFail();
    }

    private static function feeVoidFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Fee could not be voided'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
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
                SystemPermission::ViewFinancialData
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

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state instanceof EnrollmentFeeStatus
            ? $state
            : EnrollmentFeeStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            EnrollmentFeeStatus::Active =>
            'success',

            EnrollmentFeeStatus::Voided =>
            'danger',

            default =>
            'gray',
        };
    }

    private static function statusLabel(
        mixed $state
    ): string {
        if (
            $state
            instanceof EnrollmentFeeStatus
        ) {
            return $state->label();
        }

        $status =
            EnrollmentFeeStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return $status?->label()
            ?? ucfirst(
                (string) (
                    $state->value
                    ?? $state
                )
            );
    }
}