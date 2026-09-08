<?php

namespace App\Filament\Resources\AuditRecords;

use App\Filament\Resources\AuditRecords\Pages\ListAuditRecords;
use App\Filament\Resources\AuditRecords\Pages\ViewAuditRecord;
use App\Models\AuditRecord;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditRecordResource extends Resource
{
    protected static ?string $model =
    AuditRecord::class;

    protected static ?string $navigationLabel =
    'Audit Records';

    protected static ?string $modelLabel =
    'Audit Record';

    protected static ?string $pluralModelLabel =
    'Audit Records';

    protected static string | \UnitEnum | null $navigationGroup =
    'Administration';

    protected static ?int $navigationSort =
    30;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Audit Event'
                )
                    ->schema([
                        TextEntry::make(
                            'occurred_at'
                        )
                            ->label(
                                'Occurred At'
                            )
                            ->dateTime(),

                        TextEntry::make(
                            'action_type'
                        )
                            ->label(
                                'Action Type'
                            )
                            ->badge()
                            ->color(
                                'info'
                            ),

                        TextEntry::make(
                            'actor.account_login_identifier'
                        )
                            ->label(
                                'Actor Account'
                            ),

                        TextEntry::make(
                            'actor_role'
                        )
                            ->label(
                                'Actor Role'
                            ),

                        TextEntry::make(
                            'branch.name'
                        )
                            ->label(
                                'Branch'
                            )
                            ->placeholder(
                                'Center-wide'
                            ),

                        TextEntry::make(
                            'subject_type'
                        )
                            ->label(
                                'Subject Type'
                            ),

                        TextEntry::make(
                            'subject_id'
                        )
                            ->label(
                                'Subject ID'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Before Values'
                )
                    ->schema([
                        TextEntry::make(
                            'before_values'
                        )
                            ->label('')
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::jsonState(
                                    $state
                                )
                            )
                            ->placeholder(
                                'No before values recorded'
                            ),
                    ]),

                Section::make(
                    'After Values'
                )
                    ->schema([
                        TextEntry::make(
                            'after_values'
                        )
                            ->label('')
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::jsonState(
                                    $state
                                )
                            )
                            ->placeholder(
                                'No after values recorded'
                            ),
                    ]),

                Section::make(
                    'Metadata'
                )
                    ->schema([
                        TextEntry::make(
                            'metadata'
                        )
                            ->label('')
                            ->formatStateUsing(
                                fn(
                                    mixed $state
                                ): string =>
                                static::jsonState(
                                    $state
                                )
                            )
                            ->placeholder(
                                'No metadata recorded'
                            ),
                    ]),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make(
                    'occurred_at'
                )
                    ->label(
                        'Occurred At'
                    )
                    ->dateTime()
                    ->sortable(),

                TextColumn::make(
                    'actor.account_login_identifier'
                )
                    ->label(
                        'Actor'
                    )
                    ->searchable(),

                TextColumn::make(
                    'actor_role'
                )
                    ->label(
                        'Role'
                    )
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make(
                    'action_type'
                )
                    ->label(
                        'Action'
                    )
                    ->searchable()
                    ->badge()
                    ->color(
                        'info'
                    ),

                TextColumn::make(
                    'subject_type'
                )
                    ->label(
                        'Subject Type'
                    )
                    ->searchable(),

                TextColumn::make(
                    'subject_id'
                )
                    ->label(
                        'Subject ID'
                    ),

                TextColumn::make(
                    'branch.name'
                )
                    ->label(
                        'Branch'
                    )
                    ->placeholder(
                        'Center-wide'
                    ),
            ])
            ->filters([
                Filter::make(
                    'actor'
                )
                    ->schema([
                        TextInput::make(
                            'account_login_identifier'
                        )
                            ->label(
                                'Actor Account'
                            ),
                    ])
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ): Builder {
                            $identifier =
                                trim(
                                    (string) (
                                        $data['account_login_identifier']
                                        ?? ''
                                    )
                                );

                            if ($identifier === '') {
                                return $query;
                            }

                            return $query
                                ->whereHas(
                                    'actor',
                                    function (
                                        Builder $actorQuery
                                    ) use (
                                        $identifier
                                    ): void {
                                        $actorQuery
                                            ->where(
                                                'account_login_identifier',
                                                'like',
                                                '%'
                                                    . $identifier
                                                    . '%'
                                            );
                                    }
                                );
                        }
                    ),

                Filter::make(
                    'occurred_at'
                )
                    ->schema([
                        DatePicker::make(
                            'from'
                        )
                            ->label(
                                'From'
                            ),

                        DatePicker::make(
                            'until'
                        )
                            ->label(
                                'Until'
                            ),
                    ])
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ): Builder {
                            return $query
                                ->when(
                                    $data['from']
                                        ?? null,
                                    fn(
                                        Builder $query,
                                        mixed $date
                                    ): Builder =>
                                    $query->whereDate(
                                        'occurred_at',
                                        '>=',
                                        $date
                                    )
                                )
                                ->when(
                                    $data['until']
                                        ?? null,
                                    fn(
                                        Builder $query,
                                        mixed $date
                                    ): Builder =>
                                    $query->whereDate(
                                        'occurred_at',
                                        '<=',
                                        $date
                                    )
                                );
                        }
                    ),

                Filter::make(
                    'action'
                )
                    ->schema([
                        TextInput::make(
                            'action_type'
                        )
                            ->label(
                                'Action Type'
                            ),
                    ])
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ): Builder {
                            $actionType =
                                trim(
                                    (string) (
                                        $data['action_type']
                                        ?? ''
                                    )
                                );

                            if ($actionType === '') {
                                return $query;
                            }

                            return $query->where(
                                'action_type',
                                'like',
                                '%'
                                    . $actionType
                                    . '%'
                            );
                        }
                    ),

                Filter::make(
                    'subject'
                )
                    ->schema([
                        TextInput::make(
                            'subject_type'
                        )
                            ->label(
                                'Subject Type'
                            ),

                        TextInput::make(
                            'subject_id'
                        )
                            ->label(
                                'Subject ID'
                            )
                            ->integer()
                            ->minValue(1),
                    ])
                    ->query(
                        function (
                            Builder $query,
                            array $data
                        ): Builder {
                            $subjectType =
                                trim(
                                    (string) (
                                        $data['subject_type']
                                        ?? ''
                                    )
                                );

                            if ($subjectType !== '') {
                                $query->where(
                                    'subject_type',
                                    'like',
                                    '%'
                                        . $subjectType
                                        . '%'
                                );
                            }

                            $subjectId =
                                $data['subject_id']
                                ?? null;

                            if (
                                $subjectId !== null
                                && $subjectId !== ''
                            ) {
                                $query->where(
                                    'subject_id',
                                    (int) $subjectId
                                );
                            }

                            return $query;
                        }
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort(
                'occurred_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            AuditRecord::withoutGlobalScopes()
            ->with([
                'actor',
                'center',
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

        $query->where(
            'audit_records.center_id',
            $centerId
        );

        if (
            $actor->systemRole()
            === SystemRole::CenterOwner
        ) {
            return $query;
        }

        if (
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return static::denyQuery(
                $query
            );
        }

        $branchId =
            app(
                BranchContext::class
            )->branchId();

        if ($branchId === null) {
            return static::denyQuery(
                $query
            );
        }

        return $query->where(
            'audit_records.branch_id',
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
            ! $record instanceof AuditRecord
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

    public static function canReplicate(
        Model $record
    ): bool {
        return false;
    }

    /**
     * Audit history is intentionally excluded from
     * Filament global search.
     *
     * Search remains available inside the explicitly
     * authorized Resource table.
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
            ListAuditRecords::route(
                '/'
            ),

            'view' =>
            ViewAuditRecord::route(
                '/{record}'
            ),
        ];
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
                ::ViewAuditRecords
            )
        ) {
            return false;
        }

        $tenant =
            app(
                TenantContext::class
            );

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
            $actor->systemRole()
            !== SystemRole::BranchManager
        ) {
            return false;
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

        return $actor
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
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query
            ->whereRaw(
                '1 = 0'
            );
    }

    private static function jsonState(
        mixed $state
    ): string {
        if (
            $state === null
            || $state === []
        ) {
            return '';
        }

        if (
            ! is_array(
                $state
            )
        ) {
            return (string) $state;
        }

        $json =
            json_encode(
                $state,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            );

        return is_string(
            $json
        )
            ? $json
            : '';
    }
}