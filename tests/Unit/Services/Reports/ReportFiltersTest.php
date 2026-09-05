<?php

namespace Tests\Unit\Services\Reports;

use App\Services\Reports\ReportFilters;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportFiltersTest extends TestCase
{
    public function test_it_accepts_empty_filters(): void
    {
        $filters = ReportFilters::fromArray([]);

        $this->assertNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
        $this->assertNull($filters->branchId);
        $this->assertNull($filters->courseId);
        $this->assertNull($filters->classId);
        $this->assertNull($filters->studentId);
        $this->assertNull($filters->status);
        $this->assertNull($filters->currency);

        $this->assertSame([], $filters->toArray());
    }

    public function test_it_normalizes_supported_filters(): void
    {
        $filters = ReportFilters::fromArray([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'branch_id' => '2',
            'course_id' => '3',
            'class_id' => '4',
            'student_id' => '5',
            'status' => ' ACTIVE ',
            'currency' => ' usd ',
        ]);

        $this->assertSame('2026-08-01', $filters->dateFrom);
        $this->assertSame('2026-08-31', $filters->dateTo);
        $this->assertSame(2, $filters->branchId);
        $this->assertSame(3, $filters->courseId);
        $this->assertSame(4, $filters->classId);
        $this->assertSame(5, $filters->studentId);
        $this->assertSame('active', $filters->status);
        $this->assertSame('USD', $filters->currency);

        $this->assertSame([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'branch_id' => 2,
            'course_id' => 3,
            'class_id' => 4,
            'student_id' => 5,
            'status' => 'active',
            'currency' => 'USD',
        ], $filters->toArray());
    }

    public function test_it_rejects_unknown_filters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown report filter(s): secret_filter');

        ReportFilters::fromArray([
            'secret_filter' => 'value',
        ]);
    }

    public function test_it_rejects_non_positive_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'branch_id must be a positive integer.'
        );

        ReportFilters::fromArray([
            'branch_id' => 0,
        ]);
    }

    public function test_it_rejects_invalid_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'date_from must be a valid YYYY-MM-DD date.'
        );

        ReportFilters::fromArray([
            'date_from' => '2026-02-30',
        ]);
    }

    public function test_it_rejects_an_inverted_date_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'date_from must be earlier than or equal to date_to.'
        );

        ReportFilters::fromArray([
            'date_from' => '2026-08-31',
            'date_to' => '2026-08-01',
        ]);
    }

    public function test_blank_optional_string_filters_become_null(): void
    {
        $filters = ReportFilters::fromArray([
            'status' => '   ',
            'currency' => '',
        ]);

        $this->assertNull($filters->status);
        $this->assertNull($filters->currency);
        $this->assertSame([], $filters->toArray());
    }

    public function test_it_rejects_invalid_currency_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Currency filter must use a three-letter currency code.'
        );

        ReportFilters::fromArray([
            'currency' => 'US',
        ]);
    }
}
