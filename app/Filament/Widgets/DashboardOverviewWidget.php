<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Reports\DashboardReadService;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\EnrollmentStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Overview';

    protected ?string $description =
    'Current operational summary based on your authorized scope.';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        $dashboard =
            app(DashboardReadService::class)
            ->forUser(
                $actor
            );

        $stats = [];

        if ($dashboard['enrollments'] !== null) {
            $stats[] =
                Stat::make(
                    'Total Enrollments',
                    $dashboard['enrollments']['total']
                );

            $stats[] =
                Stat::make(
                    'Active Enrollments',
                    $dashboard['enrollments']['by_status'][EnrollmentStatus::Active->value] ?? 0
                );
        }

        if ($dashboard['classes'] !== null) {
            $stats[] =
                Stat::make(
                    'Total Classes',
                    $dashboard['classes']['total']
                );

            $stats[] =
                Stat::make(
                    'Active Classes',
                    $dashboard['classes']['by_status'][CourseClassStatus::Active->value] ?? 0
                );
        }

        if ($dashboard['attendance'] !== null) {
            $attendancePercentage =
                $dashboard['attendance']['attendance_percentage'];

            $stats[] =
                Stat::make(
                    'Attendance',
                    $attendancePercentage === null
                        ? 'No recorded attendance'
                        : $attendancePercentage . '%'
                )
                ->description(
                    $dashboard['attendance']['recorded_sessions']
                        . ' recorded sessions'
                );
        }

        if ($dashboard['finance'] !== null) {
            $this->appendFinanceStats(
                $stats,
                $dashboard['finance']
            );
        }

        return $stats;
    }

    /**
     * @param array<int, Stat> $stats
     * @param array<string, mixed> $finance
     */
    private function appendFinanceStats(
        array &$stats,
        array $finance
    ): void {
        if (
            $finance['report_type']
            === 'operational'
        ) {
            $currencies =
                $finance['data']['currencies'] ?? [];

            if ($currencies === []) {
                $stats[] =
                    Stat::make(
                        'Financial Summary',
                        'No financial activity'
                    );

                return;
            }

            foreach (
                $currencies
                as $currencyCode => $currency
            ) {
                $stats[] =
                    Stat::make(
                        'Outstanding · '
                            . $currencyCode,
                        $currency['outstanding_amount'] . ' ' . $currencyCode
                    )
                    ->description(
                        $currency['active_fee_count']
                            . ' active fees'
                    );

                $stats[] =
                    Stat::make(
                        'Collected · '
                            . $currencyCode,
                        $currency['posted_payment_amount'] . ' ' . $currencyCode
                    )
                    ->description(
                        $currency['posted_payment_count']
                            . ' posted payments'
                    );
            }

            return;
        }

        if (
            $finance['report_type']
            !== 'student_balance'
        ) {
            return;
        }

        /*
         * Student dashboards are currently rendered by the
         * application UI rather than Filament. Keeping this
         * branch makes the widget defensive if that changes.
         */
        $balances =
            $finance['data']['totals'] ?? [];

        foreach (
            $balances
            as $currencyCode => $balance
        ) {
            $stats[] =
                Stat::make(
                    'Balance · '
                        . $currencyCode,
                    $balance . ' ' . $currencyCode
                );
        }
    }
}