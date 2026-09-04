<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\FinanceEmployeeAssignment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Enums\PaymentStatus;
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

class PaymentResource extends Resource
{
    protected static ?string $model =
    Payment::class;

    protected static ?string $navigationLabel =
    'Payments';

    protected static ?string $modelLabel =
    'Payment';

    protected static ?string $pluralModelLabel =
    'Payments';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Payment'
                )
                    ->schema([
                        TextEntry::make(
                            'receipt_number'
                        )
                            ->label(
                                'Receipt Number'
                            ),

                        TextEntry::make(
                            'status'
                        )
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusLabel(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'amount'
                        )
                            ->label('Amount'),

                        TextEntry::make(
                            'currency_code'
                        )
                            ->label(
                                'Currency'
                            ),

                        TextEntry::make(
                            'payment_method'
                        )
                            ->label(
                                'Payment Method'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                ucfirst(
                                    str_replace(
                                        '_',
                                        ' ',
                                        (string) $state
                                    )
                                )
                            ),

                        TextEntry::make(
                            'paid_at'
                        )
                            ->label(
                                'Paid At'
                            )
                            ->dateTime(
                                'Y-m-d H:i:s'
                            ),

                        TextEntry::make(
                            'reference'
                        )
                            ->label(
                                'Reference'
                            )
                            ->placeholder(
                                'Not provided'
                            ),

                        TextEntry::make(
                            'notes'
                        )
                            ->label(
                                'Notes'
                            )
                            ->placeholder(
                                'No notes'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(
                    'Student and Branch'
                )
                    ->schema([
                        TextEntry::make(
                            'student.person.full_name'
                        )
                            ->label(
                                'Student'
                            ),

                        TextEntry::make(
                            'student.person.national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label(
                                'Financial Branch'
                            ),

                        TextEntry::make(
                            'receivedBy.person.full_name'
                        )
                            ->label(
                                'Received By'
                            )
                            ->placeholder(
                                'Unknown User'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Allocations'
                )
                    ->schema([
                        RepeatableEntry::make(
                            'allocations'
                        )
                            ->label('')
                            ->schema([
                                TextEntry::make(
                                    'amount'
                                )
                                    ->label(
                                        'Allocated Amount'
                                    ),

                                TextEntry::make(
                                    'feeInstallment.sequence_number'
                                )
                                    ->label(
                                        'Installment'
                                    ),

                                TextEntry::make(
                                    'feeInstallment.due_date'
                                )
                                    ->label(
                                        'Due Date'
                                    )
                                    ->date(
                                        'Y-m-d'
                                    ),

                                TextEntry::make(
                                    'feeInstallment.enrollmentFee.currency_code'
                                )
                                    ->label(
                                        'Currency'
                                    ),

                                TextEntry::make(
                                    'feeInstallment.enrollmentFee.enrollment.enrollment_number'
                                )
                                    ->label(
                                        'Enrollment Number'
                                    ),

                                TextEntry::make(
                                    'feeInstallment.enrollmentFee.id'
                                )
                                    ->label(
                                        'Fee ID'
                                    ),
                            ])
                            ->columns(2),
                    ]),

                Section::make(
                    'Reversal'
                )
                    ->visible(
                        fn(
                            ?Payment $record
                        ): bool =>
                        $record !== null
                            && $record
                            ->isReversed()
                    )
                    ->schema([
                        TextEntry::make(
                            'reversed_at'
                        )
                            ->label(
                                'Reversed At'
                            )
                            ->dateTime(
                                'Y-m-d H:i:s'
                            ),

                        TextEntry::make(
                            'reversedBy.person.full_name'
                        )
                            ->label(
                                'Reversed By'
                            )
                            ->placeholder(
                                'Unknown User'
                            ),

                        TextEntry::make(
                            'reversal_reason'
                        )
                            ->label(
                                'Reversal Reason'
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
                    'receipt_number'
                )
                    ->label(
                        'Receipt Number'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'student.person.full_name'
                )
                    ->label(
                        'Student'
                    )
                    ->searchable(),

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
                    'payment_method'
                )
                    ->label(
                        'Method'
                    )
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                (string) $state
                            )
                        )
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
                    ),

                TextColumn::make(
                    'paid_at'
                )
                    ->label(
                        'Paid At'
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
                            PaymentStatus::cases()
                        )
                            ->mapWithKeys(
                                fn(
                                    PaymentStatus $status
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

                Action::make(
                    'reversePayment'
                )
                    ->label(
                        'Reverse Payment'
                    )
                    ->color('danger')
                    ->visible(
                        fn(
                            Payment $record
                        ): bool =>
                        static::actorCanReversePayment(
                            $record
                        )
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Reverse Payment'
                    )
                    ->modalDescription(
                        'This will reverse the posted Payment while preserving the Payment, Receipt, and Allocation history. The reversal will be recorded in Audit.'
                    )
                    ->modalSubmitActionLabel(
                        'Reverse Payment'
                    )
                    ->schema([
                        Textarea::make(
                            'reason'
                        )
                            ->label(
                                'Reversal Reason'
                            )
                            ->required()
                            ->maxLength(255)
                            ->rows(3),
                    ])
                    ->action(
                        function (
                            Payment $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::paymentReversalFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                /*
                     * Resolve again through the current
                     * Filament Resource scope immediately
                     * before the domain operation.
                     *
                     * Never trust a stale or externally
                     * supplied Payment model instance.
                     */
                                $payment =
                                    static::resolveScopedPayment(
                                        $record
                                    );

                                $payment =
                                    app(
                                        FinanceManagementService::class
                                    )->reversePayment(
                                        $actor,
                                        $payment,
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
                                static::paymentReversalFailure(
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Payment reversed'
                                )
                                ->body(
                                    'Receipt '
                                        . $payment
                                        ->receipt_number
                                        . ' was reversed successfully.'
                                )
                                ->success()
                                ->send();
                        }
                    ),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Payment::withoutGlobalScopes()
            ->with([
                'student.person',
                'branch',
                'receivedBy.person',
                'reversedBy.person',
                'allocations.feeInstallment.enrollmentFee.enrollment',
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

        $query->where(
            'payments.center_id',
            $centerId
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
            $branchContext->branchId();

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
            'payments.branch_id',
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
            ! $record instanceof Payment
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
         * Payment creation will be exposed only through
         * a controlled Filament Action that delegates to
         * FinanceManagementService.
         *
         * Native Filament CRUD remains disabled.
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
            ListPayments::route(
                '/'
            ),

            'view' =>
            ViewPayment::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanReversePayment(
        Payment $record
    ): bool {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            return false;
        }

        if (
            ! $record->isPosted()
        ) {
            return false;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission::ManageFinancialOperations
            )
        ) {
            return false;
        }

        /*
     * canView() rechecks the record through the
     * current Center / Branch scoped Resource query.
     *
     * This also verifies the persisted active Branch
     * assignment for BranchManager and FinanceEmployee.
     */
        return static::canView(
            $record
        );
    }

    private static function resolveScopedPayment(
        Payment $record
    ): Payment {
        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->firstOrFail();
    }

    private static function paymentReversalFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Payment could not be reversed'
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

    private static function statusLabel(
        mixed $state
    ): string {
        if (
            $state instanceof
            PaymentStatus
        ) {
            return $state->label();
        }

        $status =
            PaymentStatus::tryFrom(
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