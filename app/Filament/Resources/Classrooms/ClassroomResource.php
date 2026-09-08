<?php

namespace App\Filament\Resources\Classrooms;

use App\Filament\Resources\Classrooms\Pages\ListClassrooms;
use App\Filament\Resources\Classrooms\Pages\ViewClassroom;
use App\Models\Branch;
use App\Models\Classroom;
use App\Models\User;
use App\Services\Classrooms\ClassroomManagementService;
use App\Support\Enums\BranchStatus;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Throwable;
use Filament\Actions\ActionGroup;

class ClassroomResource extends Resource
{
    protected static ?string $model =
    Classroom::class;

    protected static ?string $navigationLabel =
    'Classrooms';

    protected static ?string $modelLabel =
    'Classroom';

    protected static ?string $pluralModelLabel =
    'Classrooms';

    protected static string | \UnitEnum | null $navigationGroup =
    'Organization';

    protected static ?int $navigationSort =
    20;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Classroom Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Classroom'),

                        TextEntry::make('code')
                            ->label('Code'),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label('Branch'),

                        TextEntry::make(
                            'capacity'
                        )
                            ->label('Capacity'),

                        TextEntry::make(
                            'location'
                        )
                            ->label('Location'),

                        TextEntry::make(
                            'availability_status'
                        )
                            ->label(
                                'Availability'
                            )
                            ->badge()
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::availabilityLabel(
                                    $state
                                )
                            )
                            ->color(
                                fn(
                                    mixed $state
                                ): string =>
                                static::availabilityColor(
                                    $state
                                )
                            ),

                        TextEntry::make('status')
                            ->label(
                                'Operational Status'
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
                    ])
                    ->columns(3),

                Section::make(
                    'Usage Summary'
                )
                    ->schema([
                        TextEntry::make(
                            'assigned_course_classes_count'
                        )
                            ->label(
                                'Assigned Classes'
                            ),

                        TextEntry::make(
                            'class_schedules_count'
                        )
                            ->label(
                                'Schedules'
                            ),

                        TextEntry::make(
                            'class_sessions_count'
                        )
                            ->label(
                                'Sessions'
                            ),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Classroom')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'branch.name'
                )
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'capacity'
                )
                    ->label('Capacity')
                    ->sortable(),

                TextColumn::make(
                    'location'
                )
                    ->label('Location')
                    ->searchable()
                    ->wrap(),

                TextColumn::make(
                    'availability_status'
                )
                    ->label(
                        'Availability'
                    )
                    ->badge()
                    ->formatStateUsing(
                        fn(
                            mixed $state
                        ): string =>
                        static::availabilityLabel(
                            $state
                        )
                    )
                    ->color(
                        fn(
                            mixed $state
                        ): string =>
                        static::availabilityColor(
                            $state
                        )
                    ),

                TextColumn::make('status')
                    ->label('Status')
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
                    'assigned_course_classes_count'
                )
                    ->label('Classes')
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                SelectFilter::make(
                    'branch_id'
                )
                    ->label('Branch')
                    ->options(
                        fn(): array =>
                        static::branchOptions()
                    ),

                SelectFilter::make(
                    'availability_status'
                )
                    ->label(
                        'Availability'
                    )
                    ->options([
                        ClassroomAvailabilityStatus
                        ::Available
                            ->value =>
                        'Available',

                        ClassroomAvailabilityStatus
                        ::Unavailable
                            ->value =>
                        'Unavailable',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        ClassroomStatus::Active
                            ->value =>
                        'Active',

                        ClassroomStatus::Deactivated
                            ->value =>
                        'Deactivated',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),

                ActionGroup::make([

                    Action::make(
                        'updateClassroom'
                    )
                        ->label('Update')
                        ->color('gray')
                        ->visible(
                            fn(
                                Classroom $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                        )
                        ->modalHeading(
                            'Update Classroom'
                        )
                        ->modalDescription(
                            'Update the Classroom information. Branch, availability, and operational lifecycle are managed separately.'
                        )
                        ->modalSubmitActionLabel(
                            'Save Changes'
                        )
                        ->fillForm(
                            fn(
                                Classroom $record
                            ): array => [
                                'name' =>
                                $record->name,

                                'code' =>
                                $record->code,

                                'capacity' =>
                                $record
                                    ->capacity,

                                'location' =>
                                $record
                                    ->location,
                            ]
                        )
                        ->schema(
                            static::informationFields()
                        )
                        ->action(
                            function (
                                Classroom $record,
                                array $data
                            ): void {
                                $actor =
                                    auth()->user();

                                if (
                                    ! $actor
                                        instanceof User
                                ) {
                                    static::failure(
                                        'Classroom could not be updated.',
                                        'The authenticated User Account could not be resolved.'
                                    );

                                    return;
                                }

                                try {
                                    app(
                                        ClassroomManagementService::class
                                    )->update(
                                        $actor,
                                        $record,
                                        $data
                                    );
                                } catch (
                                    AuthorizationException
                                    | DomainException
                                    | LogicException
                                    | ModelNotFoundException
                                    $exception
                                ) {
                                    static::failure(
                                        'Classroom could not be updated.',
                                        $exception
                                            ->getMessage()
                                    );

                                    return;
                                } catch (
                                    Throwable) {
                                    static::failure(
                                        'Classroom could not be updated.',
                                        'An unexpected error occurred while updating the Classroom.'
                                    );

                                    return;
                                }

                                Notification::make()
                                    ->title(
                                        'Classroom updated'
                                    )
                                    ->success()
                                    ->send();
                            }
                        ),

                    Action::make(
                        'markAvailable'
                    )
                        ->label(
                            'Mark Available'
                        )
                        ->color('success')
                        ->visible(
                            fn(
                                Classroom $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record
                                ->availability_status
                                ===
                                ClassroomAvailabilityStatus
                                ::Unavailable
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Mark Classroom Available'
                        )
                        ->modalDescription(
                            'The Classroom will become available for valid future assignments and scheduling.'
                        )
                        ->modalSubmitActionLabel(
                            'Mark Available'
                        )
                        ->action(
                            fn(
                                Classroom $record
                            ) =>
                            static::changeAvailability(
                                $record,
                                ClassroomAvailabilityStatus
                                ::Available
                            )
                        ),

                    Action::make(
                        'markUnavailable'
                    )
                        ->label(
                            'Mark Unavailable'
                        )
                        ->color('warning')
                        ->visible(
                            fn(
                                Classroom $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record
                                ->availability_status
                                ===
                                ClassroomAvailabilityStatus
                                ::Available
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Mark Classroom Unavailable'
                        )
                        ->modalDescription(
                            'The Classroom will no longer be selectable for new operational assignments while it remains unavailable.'
                        )
                        ->modalSubmitActionLabel(
                            'Mark Unavailable'
                        )
                        ->action(
                            fn(
                                Classroom $record
                            ) =>
                            static::changeAvailability(
                                $record,
                                ClassroomAvailabilityStatus
                                ::Unavailable
                            )
                        ),

                    Action::make(
                        'activateClassroom'
                    )
                        ->label('Activate')
                        ->color('success')
                        ->visible(
                            fn(
                                Classroom $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                ===
                                ClassroomStatus
                                ::Deactivated
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Activate Classroom'
                        )
                        ->modalDescription(
                            'The Classroom will return to Active operational status.'
                        )
                        ->modalSubmitActionLabel(
                            'Activate Classroom'
                        )
                        ->action(
                            fn(
                                Classroom $record
                            ) =>
                            static::changeLifecycle(
                                $record,
                                true
                            )
                        ),

                    Action::make(
                        'deactivateClassroom'
                    )
                        ->label('Deactivate')
                        ->color('danger')
                        ->visible(
                            fn(
                                Classroom $record
                            ): bool =>
                            static::canView(
                                $record
                            )
                                && $record->status
                                ===
                                ClassroomStatus
                                ::Active
                        )
                        ->requiresConfirmation()
                        ->modalHeading(
                            'Deactivate Classroom'
                        )
                        ->modalDescription(
                            'The Classroom will remain in historical records but will no longer be operational for new assignments.'
                        )
                        ->modalSubmitActionLabel(
                            'Deactivate Classroom'
                        )
                        ->action(
                            fn(
                                Classroom $record
                            ) =>
                            static::changeLifecycle(
                                $record,
                                false
                            )
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
            Classroom::withoutGlobalScopes()
            ->with('branch')
            ->withCount([
                'assignedCourseClasses',
                'classSchedules',
                'classSessions',
            ]);

        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return static::denyQuery(
                $query
            );
        }

        $query->where(
            'center_id',
            $scope['center_id']
        );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->where(
                'branch_id',
                $scope['branch_id']
            );
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return static::authorizedScope()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record
                instanceof Classroom
        ) {
            return false;
        }

        return static::getEloquentQuery()
            ->whereKey(
                $record->getKey()
            )
            ->exists();
    }

    /*
     * Native CRUD is disabled deliberately.
     *
     * All Classroom mutations use
     * ClassroomManagementService.
     */
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
            ListClassrooms::route('/'),

            'view' =>
            ViewClassroom::route(
                '/{record}'
            ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function informationFields(): array
    {
        return [
            TextInput::make('name')
                ->label(
                    'Classroom Name'
                )
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label(
                    'Classroom Code'
                )
                ->required()
                ->maxLength(50),

            TextInput::make(
                'capacity'
            )
                ->label('Capacity')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required(),

            TextInput::make(
                'location'
            )
                ->label('Location')
                ->required()
                ->maxLength(255),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function branchOptions(
        bool $activeOnly = false
    ): array {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return [];
        }

        $query =
            Branch::withoutGlobalScopes()
            ->where(
                'center_id',
                $scope['center_id']
            );

        if (
            $scope['branch_id']
            !== null
        ) {
            $query->whereKey(
                $scope['branch_id']
            );
        }

        if ($activeOnly) {
            $query->where(
                'status',
                BranchStatus::Active
                    ->value
            );
        }

        return $query
            ->orderBy('name')
            ->pluck(
                'name',
                'id'
            )
            ->all();
    }

    public static function resolveCreationBranch(
        mixed $branchId
    ): ?Branch {
        $scope =
            static::authorizedScope();

        if ($scope === null) {
            return null;
        }

        if (
            $scope['branch_id']
            !== null
        ) {
            return Branch
                ::withoutGlobalScopes()
                ->whereKey(
                    $scope['branch_id']
                )
                ->where(
                    'center_id',
                    $scope['center_id']
                )
                ->where(
                    'status',
                    BranchStatus::Active
                        ->value
                )
                ->first();
        }

        $branchId =
            filter_var(
                $branchId,
                FILTER_VALIDATE_INT
            );

        if (
            $branchId === false
            || $branchId <= 0
        ) {
            return null;
        }

        return Branch
            ::withoutGlobalScopes()
            ->whereKey($branchId)
            ->where(
                'center_id',
                $scope['center_id']
            )
            ->where(
                'status',
                BranchStatus::Active
                    ->value
            )
            ->first();
    }

    /**
     * @return array{
     *     center_id:int,
     *     branch_id:?int
     * }|null
     */
    private static function authorizedScope(): ?array
    {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
            || $actor->center_id
            === null
        ) {
            return null;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission
                ::ManageClassrooms
            )
        ) {
            return null;
        }

        $tenant =
            app(
                TenantContext::class
            );

        if (
            ! $tenant
                ->isCenterScoped()
            || $tenant->centerId()
            !== $actor->center_id
        ) {
            return null;
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
                return null;
            }

            return [
                'center_id' =>
                $actor->center_id,

                'branch_id' =>
                null,
            ];
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
            || ! $branchContext
                ->isBranchScoped()
        ) {
            return null;
        }

        $branch =
            $branchContext->branch();

        if (
            $branch === null
            || $branch->center_id
            !== $actor->center_id
        ) {
            return null;
        }

        $hasAssignment =
            $actor
            ->activeBranchManagerAssignment()
            ->where(
                'center_id',
                $actor->center_id
            )
            ->where(
                'branch_id',
                $branch->id
            )
            ->exists();

        if (! $hasAssignment) {
            return null;
        }

        return [
            'center_id' =>
            $actor->center_id,

            'branch_id' =>
            $branch->id,
        ];
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function changeAvailability(
        Classroom $record,
        ClassroomAvailabilityStatus $status
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Classroom availability could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            app(
                ClassroomManagementService::class
            )->setAvailability(
                $actor,
                $record,
                $status
            );
        } catch (
            AuthorizationException
            | DomainException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Classroom availability could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Classroom availability could not be changed.',
                'An unexpected error occurred while changing Classroom availability.'
            );

            return;
        }

        Notification::make()
            ->title(
                $status
                    ===
                    ClassroomAvailabilityStatus
                    ::Available
                    ? 'Classroom marked available'
                    : 'Classroom marked unavailable'
            )
            ->success()
            ->send();
    }

    private static function changeLifecycle(
        Classroom $record,
        bool $activate
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Classroom lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    ClassroomManagementService::class
                );

            if ($activate) {
                $service->activate(
                    $actor,
                    $record
                );
            } else {
                $service->deactivate(
                    $actor,
                    $record
                );
            }
        } catch (
            AuthorizationException
            | DomainException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Classroom lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (Throwable) {
            static::failure(
                'Classroom lifecycle could not be changed.',
                'An unexpected error occurred while changing the Classroom lifecycle.'
            );

            return;
        }

        Notification::make()
            ->title(
                $activate
                    ? 'Classroom activated'
                    : 'Classroom deactivated'
            )
            ->success()
            ->send();
    }

    private static function statusLabel(
        mixed $state
    ): string {
        $status =
            $state
            instanceof ClassroomStatus
            ? $state
            : ClassroomStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            ClassroomStatus::Active =>
            'Active',

            ClassroomStatus::Deactivated =>
            'Deactivated',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state
            instanceof ClassroomStatus
            ? $state
            : ClassroomStatus::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            ClassroomStatus::Active =>
            'success',

            ClassroomStatus::Deactivated =>
            'danger',

            default =>
            'gray',
        };
    }

    private static function availabilityLabel(
        mixed $state
    ): string {
        $availability =
            $state
            instanceof ClassroomAvailabilityStatus
            ? $state
            : ClassroomAvailabilityStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($availability) {
            ClassroomAvailabilityStatus
            ::Available =>
            'Available',

            ClassroomAvailabilityStatus
            ::Unavailable =>
            'Unavailable',

            default =>
            'Unknown',
        };
    }

    private static function availabilityColor(
        mixed $state
    ): string {
        $availability =
            $state
            instanceof ClassroomAvailabilityStatus
            ? $state
            : ClassroomAvailabilityStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($availability) {
            ClassroomAvailabilityStatus
            ::Available =>
            'success',

            ClassroomAvailabilityStatus
            ::Unavailable =>
            'warning',

            default =>
            'gray',
        };
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