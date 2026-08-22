<?php

namespace App\Filament\Resources\RegistrationRequests;

use App\Filament\Resources\RegistrationRequests\Pages\ListRegistrationRequests;
use App\Filament\Resources\RegistrationRequests\Pages\ViewRegistrationRequest;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Support\Enums\RegistrationRequestStatus;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\Branch;
use App\Services\Registration\RegistrationReviewService;
use App\Support\Enums\BranchStatus;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

class RegistrationRequestResource extends Resource
{
    protected static ?string $model =
    RegistrationRequest::class;

    protected static ?string $navigationLabel =
    'Registration Requests';

    protected static ?string $modelLabel =
    'Registration Request';

    protected static ?string $pluralModelLabel =
    'Registration Requests';

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Applicant Identity'
                )
                    ->schema([
                        TextEntry::make(
                            'full_name'
                        )
                            ->label(
                                'Full Name'
                            ),

                        TextEntry::make(
                            'national_id_number'
                        )
                            ->label(
                                'National ID Number'
                            ),

                        TextEntry::make(
                            'date_of_birth'
                        )
                            ->label(
                                'Date of Birth'
                            )
                            ->date(
                                'Y-m-d'
                            ),

                        TextEntry::make(
                            'city_of_residence'
                        )
                            ->label(
                                'City of Residence'
                            ),

                        TextEntry::make(
                            'email'
                        )
                            ->label(
                                'Email'
                            ),

                        TextEntry::make(
                            'phone_number'
                        )
                            ->label(
                                'Phone Number'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Registration Classification'
                )
                    ->schema([
                        TextEntry::make(
                            'status'
                        )
                            ->label(
                                'Status'
                            )
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::statusLabel(
                                    $state
                                )
                            ),

                        TextEntry::make(
                            'selectedRole.name'
                        )
                            ->label(
                                'Selected Role'
                            )
                            ->placeholder(
                                'Unclassified'
                            ),

                        TextEntry::make(
                            'selectedBranch.name'
                        )
                            ->label(
                                'Selected Branch'
                            )
                            ->placeholder(
                                'Not selected'
                            ),

                        TextEntry::make(
                            'created_at'
                        )
                            ->label(
                                'Submitted At'
                            )
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make(
                    'Review Information'
                )
                    ->schema([
                        TextEntry::make(
                            'reviewedBy.account_login_identifier'
                        )
                            ->label(
                                'Reviewed By'
                            )
                            ->placeholder(
                                'Not reviewed'
                            ),

                        TextEntry::make(
                            'reviewed_at'
                        )
                            ->label(
                                'Reviewed At'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Not reviewed'
                            ),

                        TextEntry::make(
                            'rejection_reason'
                        )
                            ->label(
                                'Rejection Reason'
                            )
                            ->placeholder(
                                'Not rejected'
                            ),
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
                    'full_name'
                )
                    ->label(
                        'Applicant'
                    )
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'email'
                )
                    ->label(
                        'Email'
                    )
                    ->searchable(),

                TextColumn::make(
                    'selectedRole.name'
                )
                    ->label(
                        'Role'
                    )
                    ->placeholder(
                        'Unclassified'
                    ),

                TextColumn::make(
                    'selectedBranch.name'
                )
                    ->label(
                        'Branch'
                    )
                    ->placeholder(
                        'Not selected'
                    ),

                TextColumn::make(
                    'status'
                )
                    ->label(
                        'Status'
                    )
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::statusLabel(
                            $state
                        )
                    ),

                TextColumn::make(
                    'created_at'
                )
                    ->label(
                        'Submitted At'
                    )
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make(
                    'status'
                )
                    ->label(
                        'Status'
                    )
                    ->options([
                        RegistrationRequestStatus::Pending
                            ->value =>
                        'Pending',

                        RegistrationRequestStatus::Approved
                            ->value =>
                        'Approved',

                        RegistrationRequestStatus::Rejected
                            ->value =>
                        'Rejected',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('selectRole')
                    ->label('Select Role')
                    ->color('gray')
                    ->visible(
                        fn(
                            RegistrationRequest $record
                        ): bool =>
                        static::canSelectRoleAction(
                            $record
                        )
                    )
                    ->schema([
                        Select::make('role')
                            ->label('System Role')
                            ->options(
                                static::reviewableRoleOptions()
                            )
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->required(),
                    ])
                    ->modalHeading(
                        'Select Registration Role'
                    )
                    ->modalDescription(
                        'Changing the role clears any previously selected Branch and requires the Branch to be selected again when applicable.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Role'
                    )
                    ->action(
                        function (
                            array $data,
                            RegistrationRequest $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (! $actor instanceof User) {
                                static::reviewFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            $targetRole =
                                SystemRole::tryFrom(
                                    (string) (
                                        $data['role']
                                        ?? ''
                                    )
                                );

                            if (
                                $targetRole === null
                                || ! array_key_exists(
                                    $targetRole->value,
                                    static::reviewableRoleOptions()
                                )
                            ) {
                                static::reviewFailure(
                                    'The selected System Role is invalid.'
                                );

                                return;
                            }

                            try {
                                app(
                                    RegistrationReviewService::class
                                )->selectRole(
                                    $actor,
                                    $record,
                                    $targetRole
                                );
                            } catch (
                                AuthorizationException
                                | DomainException $exception
                            ) {
                                static::reviewFailure(
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Registration role updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('selectBranch')
                    ->label('Select Branch')
                    ->color('gray')
                    ->visible(
                        fn(
                            RegistrationRequest $record
                        ): bool =>
                        static::canSelectBranchAction(
                            $record
                        )
                    )
                    ->schema([
                        Select::make('branch_id')
                            ->label('Branch')
                            ->options(
                                fn(): array =>
                                static::activeBranchOptions()
                            )
                            ->selectablePlaceholder(false)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),
                    ])
                    ->modalHeading(
                        'Select Registration Branch'
                    )
                    ->modalDescription(
                        'Only active Branches from the current Center may be selected.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Branch'
                    )
                    ->action(
                        function (
                            array $data,
                            RegistrationRequest $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (! $actor instanceof User) {
                                static::reviewFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            $branchId =
                                filter_var(
                                    $data['branch_id']
                                        ?? null,
                                    FILTER_VALIDATE_INT
                                );

                            if (
                                $branchId === false
                                || $branchId <= 0
                            ) {
                                static::reviewFailure(
                                    'The selected Branch is invalid.'
                                );

                                return;
                            }

                            $branch =
                                Branch::withoutGlobalScopes()
                                ->whereKey(
                                    $branchId
                                )
                                ->first();

                            if ($branch === null) {
                                static::reviewFailure(
                                    'The selected Branch no longer exists.'
                                );

                                return;
                            }

                            try {
                                app(
                                    RegistrationReviewService::class
                                )->selectBranch(
                                    $actor,
                                    $record,
                                    $branch
                                );
                            } catch (
                                AuthorizationException
                                | DomainException $exception
                            ) {
                                static::reviewFailure(
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Registration branch updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(
                        fn(
                            RegistrationRequest $record
                        ): bool =>
                        static::canRejectAction(
                            $record
                        )
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Rejection Reason')
                            ->rows(5)
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Reject Registration Request'
                    )
                    ->modalDescription(
                        'The request will be marked as Rejected and cannot be reviewed again.'
                    )
                    ->modalSubmitActionLabel(
                        'Reject Request'
                    )
                    ->action(
                        function (
                            array $data,
                            RegistrationRequest $record
                        ): void {
                            $actor =
                                auth()->user();

                            if (! $actor instanceof User) {
                                static::reviewFailure(
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    RegistrationReviewService::class
                                )->reject(
                                    $actor,
                                    $record,
                                    (string) (
                                        $data['reason']
                                        ?? ''
                                    )
                                );
                            } catch (
                                AuthorizationException
                                | DomainException $exception
                            ) {
                                static::reviewFailure(
                                    $exception->getMessage()
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Registration request rejected'
                                )
                                ->success()
                                ->send();
                        }
                    ),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }

    /**
     * Registration Requests contain sensitive personal data.
     *
     * The query must therefore be explicitly restricted by
     * the authenticated account's current operational scope.
     */
    public static function getEloquentQuery(): Builder
    {
        $query =
            RegistrationRequest::query()
            ->with([
                'selectedRole',
                'selectedBranch',
                'reviewedBy',
            ]);

        if (! static::actorCanAccessResource()) {
            return static::denyQuery(
                $query
            );
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return static::denyQuery(
                $query
            );
        }

        $tenant =
            app(TenantContext::class);

        $centerId =
            $tenant->centerId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        $query->where(
            'registration_requests.center_id',
            $centerId
        );

        if (
            $user->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $query;
        }

        if (
            $user->systemRole()
            !== SystemRole::BranchManager
        ) {
            return static::denyQuery(
                $query
            );
        }

        $branchId =
            app(BranchContext::class)
            ->branchId();

        if ($branchId === null) {
            return static::denyQuery(
                $query
            );
        }

        /*
         * A Branch Manager must never see the unclassified
         * Center-wide queue.
         *
         * The request must already:
         * 1. be classified as Student; and
         * 2. belong to the Manager's current Branch.
         */
        return $query
            ->where(
                'registration_requests.selected_branch_id',
                $branchId
            )
            ->whereHas(
                'selectedRole',
                function (
                    Builder $roleQuery
                ): void {
                    $roleQuery->where(
                        'roles.code',
                        SystemRole::Student
                            ->value
                    );
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
            ! $record
                instanceof RegistrationRequest
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
     * Registration Requests are intentionally excluded from
     * Filament global search because they contain personal data.
     *
     * Search remains available inside the already-scoped table.
     *
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
            ListRegistrationRequests::route(
                '/'
            ),

            'view' =>
            ViewRegistrationRequest::route(
                '/{record}'
            ),
        ];
    }

    private static function actorCanAccessResource(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant->isEstablished()
            || ! $tenant->isCenterScoped()
        ) {
            return false;
        }

        $centerId =
            $tenant->centerId();

        if (
            $centerId === null
            || $user->center_id
            !== $centerId
            || $user->person_id
            === null
        ) {
            return false;
        }

        $branchContext =
            app(BranchContext::class);

        return match ($user->systemRole()) {
            SystemRole::CenterOwner =>
            $branchContext->isEstablished()
                && $branchContext
                ->isCenterWide(),

            SystemRole::BranchManager =>
            static::branchManagerContextIsValid(
                $user,
                $centerId,
                $branchContext
            ),

            default => false,
        };
    }

    private static function branchManagerContextIsValid(
        User $user,
        int $centerId,
        BranchContext $branchContext
    ): bool {
        if (
            ! $branchContext
                ->isEstablished()
            || ! $branchContext
                ->isBranchScoped()
        ) {
            return false;
        }

        $branch =
            $branchContext->branch();

        if (
            $branch === null
            || $branch->center_id
            !== $centerId
        ) {
            return false;
        }

        /*
         * Defense in depth.
         *
         * EstablishFilamentBranchContext already resolves the
         * authoritative assignment, but the Resource also verifies
         * that the current Branch still matches the Manager's active
         * persisted assignment.
         */
        return $user
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->exists();
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    /**
     * @return array<string, string>
     */
    private static function reviewableRoleOptions(): array
    {
        return [
            SystemRole::Student->value =>
            SystemRole::Student->label(),

            SystemRole::Teacher->value =>
            SystemRole::Teacher->label(),

            SystemRole::FinanceEmployee->value =>
            SystemRole::FinanceEmployee->label(),

            SystemRole::BranchManager->value =>
            SystemRole::BranchManager->label(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function activeBranchOptions(): array
    {
        $tenant =
            app(TenantContext::class);

        if (
            ! $tenant->isEstablished()
            || ! $tenant->isCenterScoped()
            || $tenant->centerId() === null
        ) {
            return [];
        }

        return Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $tenant->centerId()
            )
            ->where(
                'status',
                BranchStatus::Active
            )
            ->orderBy('name')
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    private static function canSelectRoleAction(
        RegistrationRequest $record
    ): bool {
        return static::authenticatedSystemRole()
            === SystemRole::CenterOwner
            && $record->status
            === RegistrationRequestStatus::Pending;
    }

    private static function canSelectBranchAction(
        RegistrationRequest $record
    ): bool {
        if (
            static::authenticatedSystemRole()
            !== SystemRole::CenterOwner
            || $record->status
            !== RegistrationRequestStatus::Pending
        ) {
            return false;
        }

        $selectedRole =
            static::selectedSystemRole(
                $record
            );

        return in_array(
            $selectedRole,
            [
                SystemRole::Student,
                SystemRole::FinanceEmployee,
                SystemRole::BranchManager,
            ],
            true
        );
    }

    private static function canRejectAction(
        RegistrationRequest $record
    ): bool {
        if (
            $record->status
            !== RegistrationRequestStatus::Pending
        ) {
            return false;
        }

        $actorRole =
            static::authenticatedSystemRole();

        if (
            $actorRole
            === SystemRole::CenterOwner
        ) {
            return true;
        }

        if (
            $actorRole
            !== SystemRole::BranchManager
        ) {
            return false;
        }

        if (
            static::selectedSystemRole(
                $record
            )
            !== SystemRole::Student
        ) {
            return false;
        }

        $branchId =
            app(BranchContext::class)
            ->branchId();

        return $branchId !== null
            && $record->selected_branch_id
            === $branchId;
    }

    private static function authenticatedSystemRole(): ?SystemRole
    {
        $user =
            auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->systemRole();
    }

    private static function selectedSystemRole(
        RegistrationRequest $record
    ): ?SystemRole {
        $record->loadMissing(
            'selectedRole'
        );

        $code =
            $record->selectedRole?->code;

        if (! is_string($code)) {
            return null;
        }

        return SystemRole::tryFrom(
            $code
        );
    }

    private static function reviewFailure(
        string $message
    ): void {
        Notification::make()
            ->title(
                'Registration review failed'
            )
            ->body(
                $message
            )
            ->danger()
            ->send();
    }

    private static function statusLabel(
        mixed $state
    ): string {
        $status =
            $state instanceof
            RegistrationRequestStatus
            ? $state
            : RegistrationRequestStatus::tryFrom(
                (string) $state
            );

        return match ($status) {
            RegistrationRequestStatus::Pending =>
            'Pending',

            RegistrationRequestStatus::Approved =>
            'Approved',

            RegistrationRequestStatus::Rejected =>
            'Rejected',

            default =>
            'Unknown',
        };
    }
}
