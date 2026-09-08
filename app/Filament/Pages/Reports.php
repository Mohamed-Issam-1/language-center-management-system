<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Reports\ReportDatasetService;
use App\Support\Enums\CourseClassStatus;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Barryvdh\DomPDF\Facade\Pdf;

class Reports extends Page
{
    protected static ?string $navigationLabel =
    'Reports';

    protected static string | \UnitEnum | null $navigationGroup =
    'Reporting';

    protected static ?int $navigationSort =
    10;

    protected string $view =
    'filament.pages.reports';

    public string $reportType = '';

    /**
     * Raw UI filter state.
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    /**
     * @var array<string, mixed>
     */
    public array $dataset = [];

    public ?string $filterError = null;

    public function mount(): void
    {
        $reportTypes =
            array_keys(
                $this->availableReportTypes()
            );

        $this->reportType =
            $reportTypes[0] ?? '';

        if ($this->reportType !== '') {
            $this->loadReport();
        }
    }

    public static function canAccess(): bool
    {
        $actor =
            Filament::auth()
            ->user();

        if (! $actor instanceof User) {
            return false;
        }

        if (
            ! in_array(
                $actor->systemRole(),
                [
                    SystemRole::PlatformOwner,
                    SystemRole::CenterOwner,
                    SystemRole::BranchManager,
                    SystemRole::FinanceEmployee,
                ],
                true
            )
        ) {
            return false;
        }

        return Gate::forUser($actor)
            ->allows(
                SystemPermission::ViewReports->value
            );
    }

    /**
     * @return array<string, string>
     */
    public function availableReportTypes(): array
    {
        $actor =
            Filament::auth()
            ->user();

        if (! $actor instanceof User) {
            return [];
        }

        return match ($actor->systemRole()) {
            SystemRole::PlatformOwner => [
                ReportDatasetService::ACADEMIC =>
                'Academic',

                ReportDatasetService::ENROLLMENT =>
                'Enrollment',
            ],

            SystemRole::CenterOwner,
            SystemRole::BranchManager => [
                ReportDatasetService::ACADEMIC =>
                'Academic',

                ReportDatasetService::ENROLLMENT =>
                'Enrollment',

                ReportDatasetService::ATTENDANCE =>
                'Attendance',

                ReportDatasetService::FINANCIAL =>
                'Financial',
            ],

            SystemRole::FinanceEmployee => [
                ReportDatasetService::FINANCIAL =>
                'Financial',
            ],

            default => [],
        };
    }

    /**
     * Define UI filters only.
     *
     * ReportDatasetService remains authoritative for which
     * filters are actually accepted.
     *
     * @return array<string, array<string, mixed>>
     */
    public function filterDefinitions(): array
    {
        return match ($this->reportType) {
            ReportDatasetService::ACADEMIC => [
                'branch_id' => [
                    'label' =>
                    'Branch ID',

                    'type' =>
                    'number',
                ],

                'course_id' => [
                    'label' =>
                    'Course ID',

                    'type' =>
                    'number',
                ],

                'status' => [
                    'label' =>
                    'Status',

                    'type' =>
                    'select',

                    'options' =>
                    $this->courseClassStatusOptions(),
                ],
            ],

            ReportDatasetService::ENROLLMENT => [
                'date_from' => [
                    'label' =>
                    'Date From',

                    'type' =>
                    'date',
                ],

                'date_to' => [
                    'label' =>
                    'Date To',

                    'type' =>
                    'date',
                ],

                'branch_id' => [
                    'label' =>
                    'Branch ID',

                    'type' =>
                    'number',
                ],

                'class_id' => [
                    'label' =>
                    'Class ID',

                    'type' =>
                    'number',
                ],

                'student_id' => [
                    'label' =>
                    'Student ID',

                    'type' =>
                    'number',
                ],

                'status' => [
                    'label' =>
                    'Status',

                    'type' =>
                    'select',

                    'options' =>
                    $this->enrollmentStatusOptions(),
                ],
            ],

            ReportDatasetService::ATTENDANCE => [
                'branch_id' => [
                    'label' =>
                    'Branch ID',

                    'type' =>
                    'number',
                ],

                'course_id' => [
                    'label' =>
                    'Course ID',

                    'type' =>
                    'number',
                ],

                'class_id' => [
                    'label' =>
                    'Class ID',

                    'type' =>
                    'number',
                ],

                'student_id' => [
                    'label' =>
                    'Student ID',

                    'type' =>
                    'number',
                ],
            ],

            ReportDatasetService::FINANCIAL => [
                'currency' => [
                    'label' =>
                    'Currency',

                    'type' =>
                    'text',

                    'placeholder' =>
                    'USD',

                    'maxlength' =>
                    3,
                ],
            ],

            default => [],
        };
    }

    public function selectReport(
        string $reportType
    ): void {
        if (
            ! array_key_exists(
                $reportType,
                $this->availableReportTypes()
            )
        ) {
            throw new AuthorizationException(
                'The account cannot view this report type.'
            );
        }

        $this->reportType =
            $reportType;

        /*
         * A filter valid for one report may be forbidden for
         * another report, so switching reports always starts
         * with clean filter state.
         */
        $this->filters = [];

        $this->filterError = null;

        $this->loadReport();
    }

    public function applyFilters(): void
    {
        $this->filterError = null;

        try {
            $this->loadReport();
        } catch (InvalidArgumentException $exception) {
            $this->filterError =
                $exception->getMessage();
        }
    }

    public function clearFilters(): void
    {
        $this->filters = [];

        $this->filterError = null;

        $this->loadReport();
    }

    public function refreshReport(): void
    {
        $this->filterError = null;

        $this->loadReport();
    }

    public function exportCsv(): StreamedResponse
    {
        $actor =
            Filament::auth()
            ->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException(
                'Reports require an authenticated User Account.'
            );
        }

        if (
            ! array_key_exists(
                $this->reportType,
                $this->availableReportTypes()
            )
        ) {
            throw new AuthorizationException(
                'The account cannot export this report type.'
            );
        }

        /*
     * Rebuild from the authoritative dataset using the exact
     * current filters. Export never trusts the dataset stored
     * in Livewire state and never creates its own queries.
     */
        $dataset =
            app(
                ReportDatasetService::class
            )->build(
                $actor,
                $this->reportType,
                $this->currentFilters()
            );

        $filename =
            sprintf(
                'lcms-%s-report.csv',
                $dataset['report_type']
            );

        return response()
            ->streamDownload(
                function () use ($dataset): void {
                    $output =
                        fopen(
                            'php://output',
                            'wb'
                        );

                    if ($output === false) {
                        throw new \RuntimeException(
                            'Unable to open CSV output stream.'
                        );
                    }

                    /*
                 * UTF-8 BOM improves Arabic/English CSV display
                 * when opened directly in spreadsheet software.
                 */
                    fwrite(
                        $output,
                        "\xEF\xBB\xBF"
                    );

                    fputcsv(
                        $output,
                        array_values(
                            $dataset['columns']
                        )
                    );

                    $columnKeys =
                        array_keys(
                            $dataset['columns']
                        );

                    foreach (
                        $dataset['rows']
                        as $row
                    ) {
                        $values = [];

                        foreach (
                            $columnKeys
                            as $columnKey
                        ) {
                            $value =
                                $row[$columnKey]
                                ?? null;

                            $values[] =
                                $this->csvValue(
                                    $value
                                );
                        }

                        fputcsv(
                            $output,
                            $values
                        );
                    }

                    fclose(
                        $output
                    );
                },
                $filename,
                [
                    'Content-Type' =>
                    'text/csv; charset=UTF-8',
                ]
            );
    }

    public function exportPdf(): StreamedResponse
    {
        $actor =
            Filament::auth()
            ->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException(
                'Reports require an authenticated User Account.'
            );
        }

        if (
            ! array_key_exists(
                $this->reportType,
                $this->availableReportTypes()
            )
        ) {
            throw new AuthorizationException(
                'The account cannot export this report type.'
            );
        }

        /*
     * Rebuild the authoritative dataset at export time.
     *
     * Never trust the dataset currently stored in Livewire state.
     * ReportDatasetService remains responsible for authorization,
     * tenant/branch scope, filter validation and report rows.
     */
        $dataset =
            app(
                ReportDatasetService::class
            )->build(
                $actor,
                $this->reportType,
                $this->currentFilters()
            );

        $reportLabel =
            $this->availableReportTypes()[$this->reportType]
            ?? ucfirst(
                $dataset['report_type']
            );

        $filename =
            sprintf(
                'lcms-%s-report.pdf',
                $dataset['report_type']
            );

        /*
     * Dompdf's download() returns a normal Response containing
     * raw binary PDF bytes.
     *
     * Livewire download actions are transported through its JSON
     * response protocol, so return a StreamedResponse instead.
     * Livewire captures the stream and safely Base64-encodes it
     * for the browser download.
     */
        $pdf =
            Pdf::loadView(
                'reports.pdf',
                [
                    'dataset' =>
                    $dataset,

                    'reportLabel' =>
                    $reportLabel,

                    'generatedAt' =>
                    now()->format(
                        'Y-m-d H:i:s'
                    ),
                ]
            )
            ->setPaper(
                'a4',
                'landscape'
            );

        $pdfContent =
            $pdf->output();

        return response()
            ->streamDownload(
                function () use ($pdfContent): void {
                    echo $pdfContent;
                },
                $filename,
                [
                    'Content-Type' =>
                    'application/pdf',
                ]
            );
    }

    private function loadReport(): void
    {
        $actor =
            Filament::auth()
            ->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException(
                'Reports require an authenticated User Account.'
            );
        }

        if (
            ! array_key_exists(
                $this->reportType,
                $this->availableReportTypes()
            )
        ) {
            throw new AuthorizationException(
                'The account cannot view this report type.'
            );
        }

        $this->dataset =
            app(
                ReportDatasetService::class
            )->build(
                $actor,
                $this->reportType,
                $this->currentFilters()
            );
    }

    /**
     * Only pass non-blank filters defined for the current report.
     *
     * @return array<string, mixed>
     */
    private function currentFilters(): array
    {
        $definitions =
            $this->filterDefinitions();

        $result = [];

        foreach (
            $definitions
            as $key => $definition
        ) {
            if (
                ! array_key_exists(
                    $key,
                    $this->filters
                )
            ) {
                continue;
            }

            $value =
                $this->filters[$key];

            if (
                $value === null
                || (
                    is_string($value)
                    && trim($value) === ''
                )
            ) {
                continue;
            }

            if (
                ($definition['type'] ?? null)
                === 'number'
                && ctype_digit(
                    (string) $value
                )
            ) {
                $value =
                    (int) $value;
            }

            if (is_string($value)) {
                $value =
                    trim(
                        $value
                    );
            }

            $result[$key] =
                $value;
        }

        return $result;
    }

    private function csvValue(
        mixed $value
    ): string|int|float {
        if ($value === null) {
            return '';
        }

        /*
     * Preserve genuine numeric PHP values.
     *
     * This is important for financial reports so negative
     * numbers remain numbers rather than being escaped as text.
     */
        if (
            is_int($value)
            || is_float($value)
        ) {
            return $value;
        }

        if (is_bool($value)) {
            return $value
                ? '1'
                : '0';
        }

        $string =
            (string) $value;

        /*
     * Spreadsheet applications may interpret text beginning with
     * =, +, -, @, TAB or CR as a formula when a CSV file is opened.
     *
     * Prefix suspicious text with an apostrophe so it is treated
     * as literal content instead of executable spreadsheet input.
     */
        $check =
            ltrim(
                $string,
                " \t\r"
            );

        if (
            $string !== ''
            && in_array(
                $string[0],
                [
                    "\t",
                    "\r",
                ],
                true
            )
        ) {
            return "'" . $string;
        }

        if (
            $check !== ''
            && in_array(
                $check[0],
                [
                    '=',
                    '+',
                    '-',
                    '@',
                ],
                true
            )
        ) {
            return "'" . $string;
        }

        return $string;
    }

    /**
     * @return array<string, string>
     */
    private function courseClassStatusOptions(): array
    {
        $options = [];

        foreach (
            CourseClassStatus::cases()
            as $status
        ) {
            $options[$status->value] =
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $status->value
                    )
                );
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function enrollmentStatusOptions(): array
    {
        $options = [];

        foreach (
            EnrollmentStatus::cases()
            as $status
        ) {
            $options[$status->value] =
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $status->value
                    )
                );
        }

        return $options;
    }
}