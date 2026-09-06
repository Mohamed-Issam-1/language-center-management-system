<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportFilters
{
    private const ALLOWED_KEYS = [
        'date_from',
        'date_to',
        'branch_id',
        'course_id',
        'class_id',
        'student_id',
        'status',
        'currency',
    ];

    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?int $branchId = null,
        public ?int $courseId = null,
        public ?int $classId = null,
        public ?int $studentId = null,
        public ?string $status = null,
        public ?string $currency = null,
    ) {
        if (
            $this->dateFrom !== null
            && $this->dateTo !== null
            && $this->dateFrom > $this->dateTo
        ) {
            throw new InvalidArgumentException(
                'date_from must be earlier than or equal to date_to.'
            );
        }
    }

    public static function fromArray(array $filters): self
    {
        self::ensureKnownKeys($filters);

        return new self(
            dateFrom: self::nullableDate($filters['date_from'] ?? null, 'date_from'),
            dateTo: self::nullableDate($filters['date_to'] ?? null, 'date_to'),
            branchId: self::nullablePositiveInteger($filters['branch_id'] ?? null, 'branch_id'),
            courseId: self::nullablePositiveInteger($filters['course_id'] ?? null, 'course_id'),
            classId: self::nullablePositiveInteger($filters['class_id'] ?? null, 'class_id'),
            studentId: self::nullablePositiveInteger($filters['student_id'] ?? null, 'student_id'),
            status: self::nullableLowercaseString(
                $filters['status'] ?? null,
                'status',
            ),
            currency: self::nullableCurrency(
                $filters['currency'] ?? null,
            ),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'branch_id' => $this->branchId,
            'course_id' => $this->courseId,
            'class_id' => $this->classId,
            'student_id' => $this->studentId,
            'status' => $this->status,
            'currency' => $this->currency,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private static function ensureKnownKeys(array $filters): void
    {
        $unknownKeys = array_diff(
            array_keys($filters),
            self::ALLOWED_KEYS,
        );

        if ($unknownKeys === []) {
            return;
        }

        throw new InvalidArgumentException(
            'Unknown report filter(s): ' . implode(', ', $unknownKeys)
        );
    }

    private static function nullablePositiveInteger(
        mixed $value,
        string $key,
    ): ?int {
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ],
        );

        if ($validated === false) {
            throw new InvalidArgumentException(
                "{$key} must be a positive integer."
            );
        }

        return $validated;
    }

    private static function nullableLowercaseString(
        mixed $value,
        string $key,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "{$key} must be a string."
            );
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    private static function nullableCurrency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Currency filter must be a string.'
            );
        }

        $currency = strtoupper(trim($value));

        if (
            preg_match(
                '/\A[A-Z]{3}\z/',
                $currency
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Currency filter must use a three-letter currency code.'
            );
        }

        return $currency;
    }

    private static function nullableDate(
        mixed $value,
        string $key,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "{$key} must use YYYY-MM-DD format."
            );
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        $hasErrors = $errors !== false
            && (
                $errors['warning_count'] > 0
                || $errors['error_count'] > 0
            );

        if (
            $date === false
            || $hasErrors
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                "{$key} must be a valid YYYY-MM-DD date."
            );
        }

        return $value;
    }
}
