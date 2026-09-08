<?php

namespace App\Filament\Resources\Branches\Support;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use App\Models\BranchManagerAssignment;
use App\Models\User;
use App\Services\Branches\StaffBranchAssignmentService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;
use Throwable;
use App\Models\FinanceEmployeeAssignment;

final class BranchStaffAssignmentActions
{
    /**
     * @return array<Action>
     */
    public static function managerActions(): array
    {
        return [
            Action::make(
                'setBranchManager'
            )
                ->label(
                    'Assign / Replace Manager'
                )
                ->color('primary')
                ->visible(
                    fn(
                        Branch $record
                    ): bool =>
                    BranchResource::canView(
                        $record
                    )
                        && $record->status
                        === BranchStatus::Active
                )
                ->modalHeading(
                    'Branch Manager'
                )
                ->modalDescription(
                    'Assign an available Branch Manager to this Branch. If the Branch already has a Manager, the existing assignment will be ended and preserved in history.'
                )
                ->modalSubmitActionLabel(
                    'Save Manager'
                )
                ->schema([
                    Select::make(
                        'manager_user_id'
                    )
                        ->label(
                            'Branch Manager'
                        )
                        ->options(
                            fn(): array =>
                            self::managerOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->helperText(
                            'Only active Branch Manager accounts without another active Branch assignment are listed.'
                        ),
                ])
                ->action(
                    function (
                        Branch $record,
                        array $data
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor
                                instanceof User
                        ) {
                            self::failure(
                                'Manager assignment failed.',
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        $managerId =
                            filter_var(
                                $data['manager_user_id'] ?? null,
                                FILTER_VALIDATE_INT
                            );

                        if (
                            $managerId === false
                            || $managerId <= 0
                        ) {
                            self::failure(
                                'Manager assignment failed.',
                                'A valid Branch Manager must be selected.'
                            );

                            return;
                        }

                        try {
                            $manager =
                                self::resolveAssignableManager(
                                    $record,
                                    $managerId
                                );

                            $currentAssignment =
                                self::activeManagerAssignment(
                                    $record
                                );

                            $service =
                                app(
                                    StaffBranchAssignmentService::class
                                );

                            if (
                                $currentAssignment
                                === null
                            ) {
                                $service
                                    ->assignBranchManager(
                                        $actor,
                                        $manager,
                                        $record
                                    );
                            } else {
                                $service
                                    ->replaceBranchManager(
                                        $actor,
                                        $record,
                                        $manager
                                    );
                            }
                        } catch (
                            ModelNotFoundException) {
                            self::failure(
                                'Manager assignment failed.',
                                'The selected Branch Manager is no longer available for assignment.'
                            );

                            return;
                        } catch (
                            AuthorizationException
                            | DomainException
                            | InvalidArgumentException
                            | LogicException
                            $exception
                        ) {
                            self::failure(
                                'Manager assignment failed.',
                                $exception->getMessage()
                            );

                            return;
                        } catch (Throwable) {
                            self::failure(
                                'Manager assignment failed.',
                                'An unexpected error occurred while assigning the Branch Manager.'
                            );

                            return;
                        }

                        $record->unsetRelation(
                            'activeBranchManagerAssignment'
                        );

                        Notification::make()
                            ->title(
                                $currentAssignment
                                    === null
                                    ? 'Branch Manager assigned'
                                    : 'Branch Manager replaced'
                            )
                            ->success()
                            ->send();
                    }
                ),

            Action::make(
                'endBranchManagerAssignment'
            )
                ->label(
                    'End Manager Assignment'
                )
                ->color('danger')
                ->visible(
                    fn(
                        Branch $record
                    ): bool =>
                    BranchResource::canView(
                        $record
                    )
                        && self::activeManagerAssignment(
                            $record
                        ) !== null
                )
                ->requiresConfirmation()
                ->modalHeading(
                    'End Branch Manager Assignment'
                )
                ->modalDescription(
                    'The current Manager will no longer manage this Branch. The assignment history will be preserved.'
                )
                ->modalSubmitActionLabel(
                    'End Assignment'
                )
                ->action(
                    function (
                        Branch $record
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor
                                instanceof User
                        ) {
                            self::failure(
                                'Manager assignment could not be ended.',
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        try {
                            $assignment =
                                self::activeManagerAssignment(
                                    $record
                                );

                            if (
                                $assignment === null
                            ) {
                                throw new DomainException(
                                    'This Branch does not currently have an active Branch Manager assignment.'
                                );
                            }

                            $manager =
                                $assignment->user;

                            if (
                                ! $manager
                                    instanceof User
                            ) {
                                throw new DomainException(
                                    'The assigned Branch Manager account could not be resolved.'
                                );
                            }

                            app(
                                StaffBranchAssignmentService::class
                            )
                                ->endBranchManagerAssignment(
                                    $actor,
                                    $manager
                                );
                        } catch (
                            AuthorizationException
                            | DomainException
                            | InvalidArgumentException
                            | LogicException
                            | ModelNotFoundException
                            $exception
                        ) {
                            self::failure(
                                'Manager assignment could not be ended.',
                                $exception->getMessage()
                            );

                            return;
                        } catch (Throwable) {
                            self::failure(
                                'Manager assignment could not be ended.',
                                'An unexpected error occurred while ending the Branch Manager assignment.'
                            );

                            return;
                        }

                        $record->unsetRelation(
                            'activeBranchManagerAssignment'
                        );

                        Notification::make()
                            ->title(
                                'Manager assignment ended'
                            )
                            ->success()
                            ->send();
                    }
                ),
        ];
    }

    /**
     * @return array<Action>
     */
    public static function financeActions(): array
    {
        return [
            Action::make(
                'assignFinanceEmployee'
            )
                ->label(
                    'Assign Finance Employee'
                )
                ->color('primary')
                ->visible(
                    fn(
                        Branch $record
                    ): bool =>
                    BranchResource::canView(
                        $record
                    )
                        && $record->status
                        === BranchStatus::Active
                )
                ->modalHeading(
                    'Assign Finance Employee'
                )
                ->modalDescription(
                    'Assign an available Finance Employee to this Branch. A Branch may have multiple Finance Employees, but each Finance Employee may have only one active Branch assignment.'
                )
                ->modalSubmitActionLabel(
                    'Assign Employee'
                )
                ->schema([
                    Select::make(
                        'finance_employee_user_id'
                    )
                        ->label(
                            'Finance Employee'
                        )
                        ->options(
                            fn(): array =>
                            self::financeEmployeeOptions()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->helperText(
                            'Only active Finance Employee accounts without another active Branch assignment are listed.'
                        ),
                ])
                ->action(
                    function (
                        Branch $record,
                        array $data
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor
                                instanceof User
                        ) {
                            self::failure(
                                'Finance assignment failed.',
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        $employeeId =
                            filter_var(
                                $data['finance_employee_user_id'] ?? null,
                                FILTER_VALIDATE_INT
                            );

                        if (
                            $employeeId === false
                            || $employeeId <= 0
                        ) {
                            self::failure(
                                'Finance assignment failed.',
                                'A valid Finance Employee must be selected.'
                            );

                            return;
                        }

                        try {
                            $financeEmployee =
                                self::resolveAssignableFinanceEmployee(
                                    $record,
                                    $employeeId
                                );

                            app(
                                StaffBranchAssignmentService::class
                            )->assignFinanceEmployee(
                                $actor,
                                $financeEmployee,
                                $record
                            );
                        } catch (
                            ModelNotFoundException) {
                            self::failure(
                                'Finance assignment failed.',
                                'The selected Finance Employee is no longer available for assignment.'
                            );

                            return;
                        } catch (
                            AuthorizationException
                            | DomainException
                            | InvalidArgumentException
                            | LogicException
                            $exception
                        ) {
                            self::failure(
                                'Finance assignment failed.',
                                $exception->getMessage()
                            );

                            return;
                        } catch (
                            Throwable) {
                            self::failure(
                                'Finance assignment failed.',
                                'An unexpected error occurred while assigning the Finance Employee.'
                            );

                            return;
                        }

                        $record->unsetRelation(
                            'activeFinanceEmployeeAssignments'
                        );

                        Notification::make()
                            ->title(
                                'Finance Employee assigned'
                            )
                            ->success()
                            ->send();
                    }
                ),

            Action::make(
                'endFinanceEmployeeAssignment'
            )
                ->label(
                    'End Finance Assignment'
                )
                ->color('danger')
                ->visible(
                    fn(
                        Branch $record
                    ): bool =>
                    BranchResource::canView(
                        $record
                    )
                        && self::branchHasActiveFinanceEmployees(
                            $record
                        )
                )
                ->modalHeading(
                    'End Finance Employee Assignment'
                )
                ->modalDescription(
                    'Select the Finance Employee whose assignment to this Branch should end. Historical assignment records will be preserved.'
                )
                ->modalSubmitActionLabel(
                    'End Assignment'
                )
                ->schema([
                    Select::make(
                        'finance_employee_user_id'
                    )
                        ->label(
                            'Assigned Finance Employee'
                        )
                        ->options(
                            fn(
                                Branch $record
                            ): array =>
                            self::assignedFinanceEmployeeOptions(
                                $record
                            )
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),
                ])
                ->action(
                    function (
                        Branch $record,
                        array $data
                    ): void {
                        $actor =
                            auth()->user();

                        if (
                            ! $actor
                                instanceof User
                        ) {
                            self::failure(
                                'Finance assignment could not be ended.',
                                'The authenticated User Account could not be resolved.'
                            );

                            return;
                        }

                        $employeeId =
                            filter_var(
                                $data['finance_employee_user_id'] ?? null,
                                FILTER_VALIDATE_INT
                            );

                        if (
                            $employeeId === false
                            || $employeeId <= 0
                        ) {
                            self::failure(
                                'Finance assignment could not be ended.',
                                'A valid assigned Finance Employee must be selected.'
                            );

                            return;
                        }

                        try {
                            $assignment =
                                self::resolveActiveFinanceAssignment(
                                    $record,
                                    $employeeId
                                );

                            $financeEmployee =
                                $assignment->user;

                            if (
                                ! $financeEmployee
                                    instanceof User
                            ) {
                                throw new DomainException(
                                    'The Finance Employee account could not be resolved.'
                                );
                            }

                            app(
                                StaffBranchAssignmentService::class
                            )->endFinanceEmployeeAssignment(
                                $actor,
                                $financeEmployee
                            );
                        } catch (
                            ModelNotFoundException) {
                            self::failure(
                                'Finance assignment could not be ended.',
                                'The selected Finance Employee is no longer assigned to this Branch.'
                            );

                            return;
                        } catch (
                            AuthorizationException
                            | DomainException
                            | InvalidArgumentException
                            | LogicException
                            $exception
                        ) {
                            self::failure(
                                'Finance assignment could not be ended.',
                                $exception->getMessage()
                            );

                            return;
                        } catch (
                            Throwable) {
                            self::failure(
                                'Finance assignment could not be ended.',
                                'An unexpected error occurred while ending the Finance Employee assignment.'
                            );

                            return;
                        }

                        $record->unsetRelation(
                            'activeFinanceEmployeeAssignments'
                        );

                        Notification::make()
                            ->title(
                                'Finance assignment ended'
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
    private static function managerOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id === null
        ) {
            return [];
        }

        return User::withoutGlobalScopes()
            ->where(
                'center_id',
                $actor->center_id
            )
            ->where(
                'status',
                AccountStatus::Active->value
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::BranchManager
                        ->value
                )
            )
            ->whereHas(
                'branchManager',
                function (
                    Builder $query
                ) use (
                    $actor
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $actor->center_id
                        )
                        ->where(
                            'status',
                            StaffStatus::Active
                                ->value
                        );
                }
            )
            ->whereDoesntHave(
                'activeBranchManagerAssignment',
                function (
                    Builder $query
                ): void {
                    $query
                        ->withoutGlobalScopes();
                }
            )
            ->with('person')
            ->orderBy(
                'account_login_identifier'
            )
            ->get()
            ->mapWithKeys(
                function (
                    User $user
                ): array {
                    $name =
                        trim(
                            (string) (
                                $user
                                ->person
                                ?->full_name
                                ?? ''
                            )
                        );

                    if ($name === '') {
                        $name =
                            'Unnamed Manager';
                    }

                    return [
                        $user->id =>
                        $name
                            . ' · '
                            . $user
                            ->account_login_identifier,
                    ];
                }
            )
            ->all();
    }

    private static function resolveAssignableManager(
        Branch $record,
        int $managerId
    ): User {
        return User::withoutGlobalScopes()
            ->whereKey(
                $managerId
            )
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'status',
                AccountStatus::Active->value
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::BranchManager
                        ->value
                )
            )
            ->whereHas(
                'branchManager',
                function (
                    Builder $query
                ) use (
                    $record
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $record->center_id
                        )
                        ->where(
                            'status',
                            StaffStatus::Active
                                ->value
                        );
                }
            )
            ->whereDoesntHave(
                'activeBranchManagerAssignment',
                function (
                    Builder $query
                ): void {
                    $query
                        ->withoutGlobalScopes();
                }
            )
            ->firstOrFail();
    }

    private static function activeManagerAssignment(
        Branch $record
    ): ?BranchManagerAssignment {
        return BranchManagerAssignment
            ::withoutGlobalScopes()
            ->with('user')
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'branch_id',
                $record->id
            )
            ->active()
            ->first();
    }

    /**
     * @return array<int|string, string>
     */
    private static function financeEmployeeOptions(): array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id === null
        ) {
            return [];
        }

        return User::withoutGlobalScopes()
            ->where(
                'center_id',
                $actor->center_id
            )
            ->where(
                'status',
                AccountStatus::Active->value
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::FinanceEmployee
                        ->value
                )
            )
            ->whereHas(
                'financeEmployee',
                function (
                    Builder $query
                ) use (
                    $actor
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $actor->center_id
                        )
                        ->where(
                            'status',
                            StaffStatus::Active
                                ->value
                        );
                }
            )
            ->whereDoesntHave(
                'activeFinanceEmployeeAssignment',
                function (
                    Builder $query
                ): void {
                    $query
                        ->withoutGlobalScopes();
                }
            )
            ->with('person')
            ->orderBy(
                'account_login_identifier'
            )
            ->get()
            ->mapWithKeys(
                function (
                    User $user
                ): array {
                    $name =
                        trim(
                            (string) (
                                $user
                                ->person
                                ?->full_name
                                ?? ''
                            )
                        );

                    if ($name === '') {
                        $name =
                            'Unnamed Finance Employee';
                    }

                    return [
                        $user->id =>
                        $name
                            . ' · '
                            . $user
                            ->account_login_identifier,
                    ];
                }
            )
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private static function assignedFinanceEmployeeOptions(
        Branch $record
    ): array {
        return FinanceEmployeeAssignment
            ::withoutGlobalScopes()
            ->with(
                'user.person'
            )
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'branch_id',
                $record->id
            )
            ->active()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(
                function (
                    FinanceEmployeeAssignment $assignment
                ): array {
                    $user =
                        $assignment->user;

                    if (
                        ! $user
                            instanceof User
                    ) {
                        return [];
                    }

                    $name =
                        trim(
                            (string) (
                                $user
                                ->person
                                ?->full_name
                                ?? ''
                            )
                        );

                    if ($name === '') {
                        $name =
                            'Unnamed Finance Employee';
                    }

                    return [
                        $user->id =>
                        $name
                            . ' · '
                            . $user
                            ->account_login_identifier,
                    ];
                }
            )
            ->all();
    }

    private static function resolveAssignableFinanceEmployee(
        Branch $record,
        int $employeeId
    ): User {
        return User::withoutGlobalScopes()
            ->whereKey(
                $employeeId
            )
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'status',
                AccountStatus::Active->value
            )
            ->whereHas(
                'role',
                fn(
                    Builder $query
                ): Builder =>
                $query->where(
                    'code',
                    SystemRole::FinanceEmployee
                        ->value
                )
            )
            ->whereHas(
                'financeEmployee',
                function (
                    Builder $query
                ) use (
                    $record
                ): void {
                    $query
                        ->withoutGlobalScopes()
                        ->where(
                            'center_id',
                            $record->center_id
                        )
                        ->where(
                            'status',
                            StaffStatus::Active
                                ->value
                        );
                }
            )
            ->whereDoesntHave(
                'activeFinanceEmployeeAssignment',
                function (
                    Builder $query
                ): void {
                    $query
                        ->withoutGlobalScopes();
                }
            )
            ->firstOrFail();
    }

    private static function resolveActiveFinanceAssignment(
        Branch $record,
        int $employeeId
    ): FinanceEmployeeAssignment {
        return FinanceEmployeeAssignment
            ::withoutGlobalScopes()
            ->with('user')
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'branch_id',
                $record->id
            )
            ->where(
                'user_id',
                $employeeId
            )
            ->active()
            ->firstOrFail();
    }

    private static function branchHasActiveFinanceEmployees(
        Branch $record
    ): bool {
        return FinanceEmployeeAssignment
            ::withoutGlobalScopes()
            ->where(
                'center_id',
                $record->center_id
            )
            ->where(
                'branch_id',
                $record->id
            )
            ->active()
            ->exists();
    }

    private static function failure(
        string $title,
        string $message
    ): void {
        Notification::make()
            ->title($title)
            ->body($message)
            ->danger()
            ->send();
    }
}
