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
