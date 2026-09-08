<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Auth\RedirectToApplicationLoginController;
use App\Http\Middleware\EnsurePasswordChangeCompleted;
use App\Http\Middleware\EstablishFilamentBranchContext;
use App\Http\Middleware\EstablishTenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\NavigationGroup;

class AdminPanelProvider extends PanelProvider
{
    public function panel(
        Panel $panel
    ): Panel {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandLogo(
                asset('images/logo.svg')
            )
            ->darkModeBrandLogo(
                asset('images/logo-dark.svg')
            )
            ->brandLogoHeight(
                '64px'
            )
            ->favicon(asset('images/favicon.png'))

            ->navigationGroups([
                NavigationGroup::make('Platform'),
                NavigationGroup::make('Organization'),
                NavigationGroup::make('People'),
                NavigationGroup::make('Academics'),
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Administration'),
                NavigationGroup::make('Reporting'),
            ])

            /*
             * Keep Filament's login route so its Authenticate
             * middleware always has a valid redirect target.
             *
             * The route itself redirects to the authoritative
             * LCMS /login screen and performs no authentication.
             */
            ->login(
                RedirectToApplicationLoginController::class
            )

            /*
             * LCMS visual identity.
             *
             * The custom panel stylesheet is intentionally isolated
             * from the React / Inertia application UI.
             */
            ->colors([
                'primary' => Color::hex('#18AEEA'),
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'gray' => Color::Slate,
            ])

            ->assets([
                Css::make(
                    'lcms-admin-design-system',
                    resource_path(
                        'css/filament/admin/lcms-admin.css'
                    )
                ),
            ])

            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\Filament\Resources'
            )

            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\Filament\Pages'
            )

            ->pages([])

            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\Filament\Widgets'
            )

            ->widgets([])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

            /*
             * Order matters:
             *
             * 1. Filament validates authenticated panel access.
             * 2. LCMS establishes authoritative tenant scope.
             * 3. Forced-password lifecycle redirects before any
             *    operational Branch requirement is enforced.
             * 4. Filament establishes the appropriate Branch scope.
             *
             * Persistent middleware ensures the same boundaries are
             * re-established for Filament Livewire interactions.
             */
            ->authMiddleware(
                [
                    Authenticate::class,
                    EstablishTenantContext::class,
                    EnsurePasswordChangeCompleted::class,
                    EstablishFilamentBranchContext::class,
                ],
                isPersistent: true
            );
    }
}
