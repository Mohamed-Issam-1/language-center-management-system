<?php

namespace App\Services\Reports;

use App\Models\User;
use InvalidArgumentException;

final class ReportDatasetService
{
    public const ACADEMIC = 'academic';

    public const ENROLLMENT = 'enrollment';

    public const ATTENDANCE = 'attendance';

    public const FINANCIAL = 'financial';

    public function __construct(
        private readonly ReportReadService $reports
    ) {}

    /**
     * Build one authorized dataset that can later be reused
     * by the UI, CSV export, and PDF export.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     report_type: string,
     *     scope: array<string, mixed>,
     *     filters: array<string, mixed>,
     *     columns: array<string, string>,
     *     rows: array<int, array<string, mixed>>,
     *     row_count: int,
     *     empty: bool
     * }
     */
    public function build(
        User $actor,
        string $reportType,
        array $filters = []
    ): array {
        $reportType =
            strtolower(
                trim(
                    $reportType
                )
            );

        return match ($reportType) {
            self::ACADEMIC =>
            $this->academic(
                $actor,
                $filters
            ),

            self::ENROLLMENT =>
            $this->enrollment(
                $actor,
                $filters
            ),

            self::ATTENDANCE =>
            $this->attendance(
                $actor,
                $filters
            ),

            self::FINANCIAL =>
            $this->financial(
                $actor,
                $filters
            ),

            default =>
            throw new InvalidArgumentException(
                'Unsupported report type.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    /**
     * @param array<string, mixed> $filters
     */
    private function academic(
        User $actor,
        array $filters
    ): array {
        $this->ensureAllowedFilters(
            $filters,
            [
                'branch_id',
                'course_id',
                'status',
            ]
        );

        $normalizedFilters =
            ReportFilters::fromArray(
                $filters
            );

        $dataset =
            $this->reports
            ->classDataset(
                $actor,
                $normalizedFilters
            );

        return $this->payload(
            reportType: self::ACADEMIC,
            scope: $dataset['scope'],
            filters: $normalizedFilters->toArray(),
            columns: [
                'class_id' =>
                'Class ID',

                'class_code' =>
                'Class Code',

                'class_name' =>
                'Class Name',

                'branch_id' =>
                'Branch ID',

                'course_id' =>
                'Course ID',

                'assigned_teacher_id' =>
                'Teacher ID',

                'start_date' =>
                'Start Date',

                'end_date' =>
                'End Date',

                'capacity' =>
                'Capacity',

                'delivery_mode' =>
                'Delivery Mode',

                'status' =>
                'Status',
            ],
            rows: $dataset['rows']
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function enrollment(
        User $actor,
        array $filters
    ): array {
        $this->ensureAllowedFilters(
            $filters,
            [
                'date_from',
                'date_to',
                'branch_id',
                'class_id',
                'student_id',
                'status',
            ]
        );

        $normalizedFilters =
            ReportFilters::fromArray(
                $filters
            );

        $dataset =
            $this->reports
            ->enrollmentDataset(
                $actor,
                $normalizedFilters
            );

        return $this->payload(
            reportType: self::ENROLLMENT,
            scope: $dataset['scope'],
            filters: $normalizedFilters->toArray(),
            columns: [
                'enrollment_id' =>
                'Enrollment ID',

                'enrollment_number' =>
                'Enrollment Number',

                'enrollment_date' =>
                'Enrollment Date',

                'status' =>
                'Status',

                'student_id' =>
                'Student ID',

                'class_id' =>
                'Class ID',

                'branch_id' =>
                'Branch ID',

                'class_code' =>
                'Class Code',

                'class_name' =>
                'Class Name',
            ],
            rows: $dataset['rows']
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function attendance(
        User $actor,
        array $filters
    ): array {
        /*
     * Date filtering is intentionally not supported here yet.
     *
     * A report period must eventually be applied by
     * AttendanceCalculationService itself so authoritative
     * attendance calculations are never post-filtered.
     */
        $this->ensureAllowedFilters(
            $filters,
            [
                'branch_id',
                'course_id',
                'class_id',
                'student_id',
            ]
        );

        $normalizedFilters =
            ReportFilters::fromArray(
                $filters
            );

        $dataset =
            $this->reports
            ->attendanceDataset(
                $actor,
                $normalizedFilters
            );

        return $this->payload(
            reportType: self::ATTENDANCE,
            scope: $dataset['scope'],
            filters: $normalizedFilters->toArray(),
            columns: [
                'enrollment_id' =>
                'Enrollment ID',

                'student_id' =>
                'Student ID',

                'class_id' =>
                'Class ID',

                'branch_id' =>
                'Branch ID',

                'course_id' =>
                'Course ID',

                'recorded_sessions' =>
                'Recorded Sessions',

                'attendance_equivalent' =>
                'Attendance Equivalent',

                'absence_equivalent' =>
                'Absence Equivalent',

                'attendance_percentage' =>
                'Attendance %',

                'absence_percentage' =>
                'Absence %',
            ],
            rows: $dataset['rows']
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function financial(
        User $actor,
        array $filters
    ): array {
        $this->ensureAllowedFilters(
            $filters,
            [
                'currency',
            ]
        );

        $normalizedFilters =
            ReportFilters::fromArray(
                $filters
            );

        $currency =
            $normalizedFilters->currency;

        $summary =
            $this->reports
            ->financeSummary(
                $actor
            );

        if (
            $summary['report_type'] === 'student_balance'
        ) {
            return $this->studentFinancialPayload(
                $summary,
                $currency
            );
        }

        return $this->operationalFinancialPayload(
            $summary,
            $currency
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function operationalFinancialPayload(
        array $summary,
        ?string $currency
    ): array {
        $rows = [];

        foreach (
            (
                $summary['data']['currencies'] ?? []
            )
            as $currencyCode => $totals
        ) {
            if (
                $currency !== null
                && $currencyCode !== $currency
            ) {
                continue;
            }

            $rows[] = [
                'currency' =>
                $currencyCode,

                'active_fee_count' =>
                (int) $totals['active_fee_count'],

                'active_fee_amount' =>
                $totals['active_fee_amount'],

                'allocated_amount' =>
                $totals['allocated_amount'],

                'outstanding_amount' =>
                $totals['outstanding_amount'],

                'posted_payment_count' =>
                (int) $totals['posted_payment_count'],

                'posted_payment_amount' =>
                $totals['posted_payment_amount'],

                'reversed_payment_count' =>
                (int) $totals['reversed_payment_count'],

                'reversed_payment_amount' =>
                $totals['reversed_payment_amount'],
            ];
        }

        return $this->payload(
            reportType: self::FINANCIAL,
            scope: $summary['scope'],
            filters: [
                'currency' =>
                $currency,
            ],
            columns: [
                'currency' =>
                'Currency',

                'active_fee_count' =>
                'Active Fees',

                'active_fee_amount' =>
                'Fee Amount',

                'allocated_amount' =>
                'Allocated',

                'outstanding_amount' =>
                'Outstanding',

                'posted_payment_count' =>
                'Posted Payments',

                'posted_payment_amount' =>
                'Collected',

                'reversed_payment_count' =>
                'Reversed Payments',

                'reversed_payment_amount' =>
                'Reversed Amount',
            ],
            rows: $rows
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function studentFinancialPayload(
        array $summary,
        ?string $currency
    ): array {
        $rows = [];

        foreach (
            (
                $summary['data']['totals'] ?? []
            )
            as $currencyCode => $totals
        ) {
            if (
                $currency !== null
                && $currencyCode !== $currency
            ) {
                continue;
            }

            $rows[] = [
                'currency' =>
                $currencyCode,

                'obligation' =>
                $totals['obligation'],

                'paid' =>
                $totals['paid'],

                'balance' =>
                $totals['balance'],
            ];
        }

        return $this->payload(
            reportType: self::FINANCIAL,
            scope: $summary['scope'],
            filters: [
                'currency' =>
                $currency,
            ],
            columns: [
                'currency' =>
                'Currency',

                'obligation' =>
                'Obligation',

                'paid' =>
                'Paid',

                'balance' =>
                'Balance',
            ],
            rows: $rows
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $allowed
     */
    private function ensureAllowedFilters(
        array $filters,
        array $allowed
    ): void {
        foreach (
            array_keys(
                $filters
            )
            as $key
        ) {
            if (
                ! is_string(
                    $key
                )
                || ! in_array(
                    $key,
                    $allowed,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Unsupported report filter.'
                );
            }
        }
    }

    /**
     * @param array<int, string> $allowedValues
     */

    /**
     * @param array<string, mixed> $filters
     */


    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $filters
     * @param array<string, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function payload(
        string $reportType,
        array $scope,
        array $filters,
        array $columns,
        array $rows
    ): array {
        return [
            'report_type' =>
            $reportType,

            'scope' =>
            $scope,

            'filters' =>
            $filters,

            'columns' =>
            $columns,

            'rows' =>
            $rows,

            'row_count' =>
            count(
                $rows
            ),

            'empty' =>
            $rows === [],
        ];
    }
}
