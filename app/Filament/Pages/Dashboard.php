<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\CenterOwners\CenterOwnerResource;
use App\Filament\Resources\Centers\CenterResource;
use App\Filament\Resources\ClassSessions\ClassSessionResource;
use App\Filament\Resources\CourseClasses\CourseClassResource;
use App\Filament\Resources\EnrollmentFees\EnrollmentFeeResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\RegistrationRequests\RegistrationRequestResource;
use App\Filament\Resources\StudentBalances\StudentBalanceResource;
use App\Models\User;
use App\Support\Enums\SystemRole;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string | Htmlable
    {
        $actor =
            auth()->user();

        if (! $actor instanceof User) {
            return 'Dashboard';
        }

        return match ($actor->systemRole()) {
            SystemRole::PlatformOwner =>
            'Platform Dashboard',

            SystemRole::CenterOwner =>
            'Center Dashboard',

            SystemRole::BranchManager =>
            'Branch Dashboard',

            SystemRole::FinanceEmployee =>
            'Finance Dashboard',

            default =>
            'Dashboard',
        };
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actor =
            auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        return match ($actor->systemRole()) {
            SystemRole::PlatformOwner =>
            $this->platformActions(),

            SystemRole::CenterOwner =>
            $this->centerActions(),

            SystemRole::BranchManager =>
            $this->branchActions(),

            SystemRole::FinanceEmployee =>
            $this->financeActions(),

            default =>
            [],
        };
    }

    /**
     * @return array<Action>
     */
    private function platformActions(): array
    {
        return [
            Action::make(
                'manageCenters'
            )
                ->label(
                    'Manage Centers'
                )
                ->icon(
                    'heroicon-o-building-office'
                )
                ->url(
                    CenterResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageCenterOwners'
            )
                ->label(
                    'Manage Center Owners'
                )
                ->icon(
                    'heroicon-o-users'
                )
                ->url(
                    CenterOwnerResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'viewReports'
            )
                ->label(
                    'View Reports'
                )
                ->icon(
                    'heroicon-o-chart-bar'
                )
                ->color(
                    'gray'
                )
                ->url(
                    Reports::getUrl()
                ),
        ];
    }

    /**
     * @return array<Action>
     */
    private function centerActions(): array
    {
        return [
            Action::make(
                'reviewRegistrations'
            )
                ->label(
                    'Review Registrations'
                )
                ->icon(
                    'heroicon-o-clipboard-document-list'
                )
                ->url(
                    RegistrationRequestResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageEnrollments'
            )
                ->label(
                    'Manage Enrollments'
                )
                ->icon(
                    'heroicon-o-user-plus'
                )
                ->url(
                    EnrollmentResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageClasses'
            )
                ->label(
                    'Manage Classes'
                )
                ->icon(
                    'heroicon-o-academic-cap'
                )
                ->url(
                    CourseClassResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'financialOperations'
            )
                ->label(
                    'Financial Operations'
                )
                ->icon(
                    'heroicon-o-banknotes'
                )
                ->color(
                    'gray'
                )
                ->url(
                    PaymentResource::getUrl(
                        'index'
                    )
                ),
        ];
    }

    /**
     * @return array<Action>
     */
    private function branchActions(): array
    {
        return [
            Action::make(
                'recordAttendance'
            )
                ->label(
                    'Record Attendance'
                )
                ->icon(
                    'heroicon-o-check-circle'
                )
                ->url(
                    AttendanceResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageSessions'
            )
                ->label(
                    'Manage Sessions'
                )
                ->icon(
                    'heroicon-o-calendar'
                )
                ->url(
                    ClassSessionResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageEnrollments'
            )
                ->label(
                    'Manage Enrollments'
                )
                ->icon(
                    'heroicon-o-user-plus'
                )
                ->url(
                    EnrollmentResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'financialOperations'
            )
                ->label(
                    'Financial Operations'
                )
                ->icon(
                    'heroicon-o-banknotes'
                )
                ->color(
                    'gray'
                )
                ->url(
                    PaymentResource::getUrl(
                        'index'
                    )
                ),
        ];
    }

    /**
     * @return array<Action>
     */
    private function financeActions(): array
    {
        return [
            Action::make(
                'recordPayments'
            )
                ->label(
                    'Record Payments'
                )
                ->icon(
                    'heroicon-o-credit-card'
                )
                ->url(
                    PaymentResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'manageFees'
            )
                ->label(
                    'Manage Fees'
                )
                ->icon(
                    'heroicon-o-document-text'
                )
                ->url(
                    EnrollmentFeeResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'studentBalances'
            )
                ->label(
                    'Student Balances'
                )
                ->icon(
                    'heroicon-o-banknotes'
                )
                ->url(
                    StudentBalanceResource::getUrl(
                        'index'
                    )
                ),

            Action::make(
                'financialReports'
            )
                ->label(
                    'Financial Reports'
                )
                ->icon(
                    'heroicon-o-chart-bar'
                )
                ->color(
                    'gray'
                )
                ->url(
                    Reports::getUrl()
                ),
        ];
    }
}
