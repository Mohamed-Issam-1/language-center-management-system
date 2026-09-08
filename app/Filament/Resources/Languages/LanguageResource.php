<?php

namespace App\Filament\Resources\Languages;

use App\Filament\Resources\Languages\Pages\ListLanguages;
use App\Filament\Resources\Languages\Pages\ViewLanguage;
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

class LanguageResource extends Resource
{
    protected static ?string $model =
        Language::class;

    protected static ?string $navigationLabel =
        'Languages';

    protected static ?string $modelLabel =
        'Language';

    protected static ?string $pluralModelLabel =
        'Languages';

    protected static string | \UnitEnum | null $navigationGroup =
    'Academics';

    protected static ?int $navigationSort =
    10;

    public static function infolist(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Language Information'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Language'),

                        TextEntry::make('code')
                            ->label('Code'),

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
                            'academic_levels_count'
                        )
                            ->label(
                                'Academic Levels'
                            ),

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
                    ->columns(3),
            ]);
    }

    public static function table(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Language')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                TextColumn::make(
                    'academic_levels_count'
                )
                    ->label('Levels')
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
                        isToggledHiddenByDefault:
                            true
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
                        isToggledHiddenByDefault:
                            true
                    ),
            ])
            ->filters([
                SelectFilter::make('status')
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
                    'updateLanguage'
                )
                    ->label('Update')
                    ->color('gray')
                    ->visible(
                        fn(
                            Language $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                    )
                    ->modalHeading(
                        'Update Language'
                    )
                    ->modalDescription(
                        'Update Language information. Center ownership and lifecycle status are managed separately.'
                    )
                    ->modalSubmitActionLabel(
                        'Save Changes'
                    )
                    ->fillForm(
                        fn(
                            Language $record
                        ): array => [
                            'name' =>
                                $record->name,

                            'code' =>
                                $record->code,

                            'description' =>
                                $record
                                    ->description,
                        ]
                    )
                    ->schema(
                        static::languageFields()
                    )
                    ->action(
                        function (
                            Language $record,
                            array $data
                        ): void {
                            $actor =
                                auth()->user();

                            if (
                                ! $actor
                                    instanceof User
                            ) {
                                static::failure(
                                    'Language could not be updated.',
                                    'The authenticated User Account could not be resolved.'
                                );

                                return;
                            }

                            try {
                                app(
                                    AcademicCatalogManagementService::class
                                )->updateLanguage(
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
                                    'Language could not be updated.',
                                    $exception
                                        ->getMessage()
                                );

                                return;
                            } catch (
                                Throwable
                            ) {
                                static::failure(
                                    'Language could not be updated.',
                                    'An unexpected error occurred while updating the Language.'
                                );

                                return;
                            }

                            Notification::make()
                                ->title(
                                    'Language updated'
                                )
                                ->success()
                                ->send();
                        }
                    ),

                Action::make(
                    'archiveLanguage'
                )
                    ->label('Archive')
                    ->color('danger')
                    ->visible(
                        fn(
                            Language $record
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
                        'Archive Language'
                    )
                    ->modalDescription(
                        'The Language will stop being available for new academic structure. It cannot be archived while Active Academic Levels still depend on it.'
                    )
                    ->modalSubmitActionLabel(
                        'Archive Language'
                    )
                    ->action(
                        fn(
                            Language $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            false
                        )
                    ),

                Action::make(
                    'restoreLanguage'
                )
                    ->label('Restore')
                    ->color('success')
                    ->visible(
                        fn(
                            Language $record
                        ): bool =>
                        static::canView(
                            $record
                        )
                        && $record->status
                            ===
                            AcademicRecordStatus
                                ::Archived
                    )
                    ->requiresConfirmation()
                    ->modalHeading(
                        'Restore Language'
                    )
                    ->modalDescription(
                        'The Language will return to Active academic status.'
                    )
                    ->modalSubmitActionLabel(
                        'Restore Language'
                    )
                    ->action(
                        fn(
                            Language $record
                        ) =>
                        static::changeLifecycle(
                            $record,
                            true
                        )
                    ),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }

    public static function getEloquentQuery(): Builder
    {
        $query =
            Language::withoutGlobalScopes()
                ->withCount([
                    'academicLevels',
                    'courses',
                ]);

        $centerId =
            static::authorizedCenterId();

        if ($centerId === null) {
            return static::denyQuery(
                $query
            );
        }

        return $query->where(
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
                instanceof Language
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
     * All academic catalog mutations use
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
                ListLanguages::route('/'),

            'view' =>
                ViewLanguage::route(
                    '/{record}'
                ),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function languageFields(): array
    {
        return [
            TextInput::make('name')
                ->label(
                    'Language Name'
                )
                ->required()
                ->maxLength(255),

            TextInput::make('code')
                ->label(
                    'Language Code'
                )
                ->required()
                ->maxLength(50),

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

    public static function currentCenterId(): ?int
    {
        return static::authorizedCenterId();
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
            ! $tenant->isCenterScoped()
            || $tenant->centerId()
                !== $actor->center_id
        ) {
            return null;
        }

        /*
         * Academic Catalog is Center-wide.
         */
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
        Language $record,
        bool $restore
    ): void {
        $actor =
            auth()->user();

        if (
            ! $actor instanceof User
        ) {
            static::failure(
                'Language lifecycle could not be changed.',
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
                $service->restoreLanguage(
                    $actor,
                    $record
                );
            } else {
                $service->archiveLanguage(
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
                'Language lifecycle could not be changed.',
                $exception->getMessage()
            );

            return;
        } catch (
            Throwable
        ) {
            static::failure(
                'Language lifecycle could not be changed.',
                'An unexpected error occurred while changing the Language lifecycle.'
            );

            return;
        }

        Notification::make()
            ->title(
                $restore
                    ? 'Language restored'
                    : 'Language archived'
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