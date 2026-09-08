<?php

namespace App\Filament\Resources\AcademicLevels;

use App\Filament\Resources\AcademicLevels\Pages\ListAcademicLevels;
use App\Filament\Resources\AcademicLevels\Pages\ViewAcademicLevel;
use App\Models\AcademicLevel;
use App\Models\Language;
use App\Models\User;
use App\Services\Academic\AcademicCatalogManagementService;
use App\Support\Enums\AcademicRecordStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
use InvalidArgumentException;
use LogicException;
use Throwable;

class AcademicLevelResource extends Resource
{
    protected static ?string $model =
    AcademicLevel::class;

    protected static ?string $navigationLabel =
    'Academic Levels';

    protected static ?string $modelLabel =
    'Academic Level';

    protected static ?string $pluralModelLabel =
    'Academic Levels';

    protected static string | \UnitEnum | null $navigationGroup =
    'Academics';

    protected static ?int $navigationSort =
    20;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Academic Level Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label(
                                'Academic Level'
                            ),

                        TextEntry::make('code')
                            ->label('Code'),

                        TextEntry::make(
                            'language.name'
                        )
                            ->label('Language'),

                        TextEntry::make(
                            'sequence_number'
                        )
                            ->label(
                                'Sequence'
                            ),

                        TextEntry::make('status')
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

                        TextEntry::make(
                            'description'
                        )
                            ->label(
                                'Description'
                            )
                            ->placeholder(
                                'No description'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(
                    'Academic Structure'
                )
                    ->schema([
                        TextEntry::make(
                            'courses_count'
                        )
                            ->label(
                                'Courses'
                            ),

                        TextEntry::make(
                            'archived_at'
                        )
                            ->label(
                                'Archived At'
                            )
                            ->dateTime()
                            ->placeholder(
                                'Not archived'
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
                    'name'
                )
                    ->label(
                        'Academic Level'
                    )
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'code'
                )
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'language.name'
                )
                    ->label('Language')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make(
                    'sequence_number'
                )
                    ->label('Sequence')
                    ->sortable(),

                TextColumn::make(
                    'courses_count'
                )
                    ->label('Courses')
                    ->sortable(),

                TextColumn::make(
                    'description'
                )
                    ->label(
                        'Description'
                    )
                    ->limit(45)
                    ->placeholder('—')
                    ->toggleable(
                        isToggledHiddenByDefault: true
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
                    'archived_at'
                )
                    ->label(
                        'Archived At'
                    )
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])

            ->filters([
                SelectFilter::make(
                    'language_id'
                )
                    ->label('Language')
                    ->options(
                        fn(): array =>
                        static::languageOptions(
                            false
                        )
                    ),

                SelectFilter::make(
                    'status'
                )
                    ->label('Status')
                    ->options([
                        AcademicRecordStatus
                        ::Active
                            ->value =>
                        'Active',

                        AcademicRecordStatus
                        ::Archived
                            ->value =>
                        'Archived',
                    ]),
            ])

            ->recordActions([
                ViewAction::make(),

                Action::make(
                    'updateAcademicLevel'
                )
                    ->label('Update')
                    ->color('gray')
                    ->visible(
                        fn(
                            AcademicLevel $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Academic Level'
                    )
                    ->modalDescription(
                        'Update Academic Level information. Parent Language, Center, and lifecycle status are managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->fillForm(
                        fn(
                            AcademicLevel $record
                        ): array => [
                            'name' =>
                            $record->name,

                            'code' =>
                            $record->code,

                            'sequence_number' =>
                            $record
                                ->sequence_number,

                            'description' =>
                            $record
                                ->description,
                        ]
                    )
                    ->schema(
                        static::levelFields()
                    )
                    ->action(
                        function (
                            AcademicLevel $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Academic Level could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    AcademicCatalogManagementService::class
                                )->updateAcademicLevel(
                                    $actor,
                                    $record,
                                    $data
                                );
                            } catch (
                                AuthorizationException
                                | DomainException
                                | InvalidArgumentException
                                | LogicException
                                | ModelNotFoundException
                                $exception
                            ) {
                                static::failure(
                                    'Academic Level could not be updated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable) {
                                static::failure(
                                    'Academic Level could not be updated.',
                                    'An unexpected error occurred while updating the Academic Level.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Academic Level updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'archiveAcademicLevel'
                )
                    ->label('Archive')
                    ->color('danger')
                    ->visible(
                        fn(
                            AcademicLevel $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                            && $record->status
                            ===
                            AcademicRecordStatus
                            ::Active
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Archive Academic Level'
                    )
                    ->modalDescription(
                        'The Academic Level will stop being available for new academic structure. It cannot be archived while Active Courses still depend on it.'
                    )
                    ->modalSubmitActionLabel(
                        'Archive Academic Level'
                    )
                    ->action(
                        fn(
                            AcademicLevel $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            false
                        )
                    ),

                Action::make(
                    'restoreAcademicLevel'
                )
                    ->label('Restore')
                    ->color('success')
                    ->visible(
                        function (
                            AcademicLevel $record
                        ): bool {
                            if (
                                ! static::canView(
                                    $record
                                )
                                || $record->status
                                !==
                                AcademicRecordStatus
                                ::Archived
                            ) {
                                return false;
                            }

                            $record->loadMissing(
                                'language'
                            );

                            return $record
                                ->language
                                ?->status
                                ===
                                AcademicRecordStatus
                                ::Active;
                        }
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Restore Academic Level'
                    )
                    ->modalDescription(
                        'The Academic Level will return to Active status. Its parent Language must already be Active.'
                    )
                    ->modalSubmitActionLabel(
                        'Restore Academic Level'
                    )
                    ->action(
                        fn(
                            AcademicLevel $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            true
                        )
                    ),
            ])

            ->defaultSort(
                'sequence_number',
                'asc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            AcademicLevel
            ::withoutGlobalScopes()
            ->with('language')
            ->withCount(
                'courses'
            );

        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        return $query
            ->where(
                'center_id',
                $centerId
            );
    }

    public static function canViewAny(): bool
    {
        return static::authorizedCenterId()
            !== null;
    }

    public static function canView(
        Model $record
    ): bool {
        if (
            ! $record
                instanceof AcademicLevel
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
     * Native CRUD is disabled.
     *
     * Academic hierarchy mutations go through
     * AcademicCatalogManagementService.
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
            ListAcademicLevels
                ::route('/'),

            'view' =>
            ViewAcademicLevel
                ::route(
                    '/{record}'
                ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function levelFields(): array
    {
        return [
            TextInput::make('name')
                ->label(
                    'Level Name'
                )
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label(
                    'Level Code'
                )
                ->required()
                ->maxLength(50),

            TextInput::make(
                'sequence_number'
            )
                ->label(
                    'Sequence Number'
                )
                ->numeric()
                ->integer()
                ->minValue(1)
                ->required()
                ->helperText(
                    'Defines the Level order inside its Language.'
                ),

            Textarea::make(
                'description'
            )
                ->label(
                    'Description'
                )
                ->rows(3)
                ->maxLength(255)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    public static function languageOptions(
        bool $activeOnly = false
    ): array {
        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return [];
        }

        $query =
            Language::withoutGlobalScopes()
            ->where(
                'center_id',
                $centerId
            );

        if ($activeOnly) {
            $query->where(
                'status',
                AcademicRecordStatus
                ::Active
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

    public static function resolveCreationLanguage(
        mixed $languageId
    ): ?Language {
        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return null;
        }

        $languageId =
            filter_var(
                $languageId,
                FILTER_VALIDATE_INT
            );

        if (
            $languageId === false
            || $languageId <= 0
        ) {
            return null;
        }

        return Language
            ::withoutGlobalScopes()
            ->whereKey(
                $languageId
            )
            ->where(
                'center_id',
                $centerId
            )
            ->where(
                'status',
                AcademicRecordStatus
                ::Active
                    ->value
            )
            ->first();
    }

    private static function authorizedCenterId(): ?int
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
            $actor->systemRole()
            !== SystemRole::CenterOwner
        ) {
            return null;
        }

        if (
            ! $actor->hasPermission(
                SystemPermission
                ::ManageAcademicStructure
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

        if (
            ! app(
                BranchContext::class
            )->isCenterWide()
        ) {
            return null;
        }

        return (int)
        $actor->center_id;
    }

    private static function denyQuery(
        Builder $query
    ): Builder {
        return $query->whereRaw(
            '1 = 0'
        );
    }

    private static function changeLifecycle(
        AcademicLevel $record,
        bool $restore
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Academic Level lifecycle could not be changed.',
                'The authenticated User Account could not be resolved.'
            );

            return;
        }

        try {
            $service =
                app(
                    AcademicCatalogManagementService::class
                );

            if ($restore) {
                $service
                    ->restoreAcademicLevel(
                        $actor,
                        $record
                    );
            } else {
                $service
                    ->archiveAcademicLevel(
                        $actor,
                        $record
                    );
            }
        } catch (
            AuthorizationException
            | DomainException
            | InvalidArgumentException
            | LogicException
            | ModelNotFoundException
            $exception
        ) {
            static::failure(
                'Academic Level lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (
            Throwable) {
            static::failure(
                'Academic Level lifecycle could not be changed.',
                'An unexpected error occurred while changing the Academic Level lifecycle.'
            );

            return;
        }

        Notification::make()
            ->title(
                $restore
                    ? 'Academic Level restored'
                    : 'Academic Level archived'
            )
            ->success()
            ->send();
    }

    private static function statusLabel(
        mixed $state
    ): string {
        $status =
            $state
            instanceof AcademicRecordStatus
            ? $state
            : AcademicRecordStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            AcademicRecordStatus::Active =>
            'Active',

            AcademicRecordStatus::Archived =>
            'Archived',

            default =>
            'Unknown',
        };
    }

    private static function statusColor(
        mixed $state
    ): string {
        $status =
            $state
            instanceof AcademicRecordStatus
            ? $state
            : AcademicRecordStatus
            ::tryFrom(
                (string) (
                    $state->value
                    ?? $state
                )
            );

        return match ($status) {
            AcademicRecordStatus::Active =>
            'success',

            AcademicRecordStatus::Archived =>
            'gray',

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