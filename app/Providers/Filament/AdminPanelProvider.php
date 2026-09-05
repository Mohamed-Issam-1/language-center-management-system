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
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(
        Panel $panel
    ): Panel {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')

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

            ->colors([
                'primary' => Color::Amber,
            ])

            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\Filament\Resources'
            )

            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\Filament\Pages'
            )

            ->pages([
                Dashboard::class,
            ])

            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\Filament\Widgets'
            )

            ->widgets([
                AccountWidget::class,
            ])

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