<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <title>{{ $reportLabel }} Report</title>

    <style>
        @page {
            margin: 24px;
        }

        body {
            margin: 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10px;
            color: #111827;
        }

        .header {
            margin-bottom: 18px;
            border-bottom: 2px solid #111827;
            padding-bottom: 10px;
        }

        .title {
            margin: 0 0 4px;
            font-size: 20px;
            font-weight: bold;
        }

        .subtitle {
            margin: 0;
            color: #4b5563;
            font-size: 10px;
        }

        .summary-table {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }

        .summary-table td {
            width: 25%;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            vertical-align: top;
        }

        .summary-label {
            display: block;
            margin-bottom: 2px;
            color: #6b7280;
            font-size: 8px;
            text-transform: uppercase;
        }

        .summary-value {
            font-weight: bold;
            font-size: 10px;
        }

        .section-title {
            margin: 14px 0 6px;
            font-size: 11px;
            font-weight: bold;
        }

        .filters {
            margin-bottom: 14px;
            border: 1px solid #d1d5db;
            padding: 7px 8px;
            background: #f9fafb;
        }

        .filter-item {
            display: inline-block;
            margin-right: 14px;
            margin-bottom: 4px;
        }

        .filter-key {
            font-weight: bold;
        }

        .empty-state {
            margin-top: 16px;
            border: 1px solid #d1d5db;
            padding: 16px;
            text-align: center;
            color: #6b7280;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #d1d5db;
            padding: 5px 4px;
            vertical-align: top;
            overflow-wrap: break-word;
        }

        .report-table th {
            background: #f3f4f6;
            font-weight: bold;
            font-size: 8px;
            text-align: left;
        }

        .report-table td {
            font-size: 8px;
        }

        .footer {
            margin-top: 14px;
            border-top: 1px solid #d1d5db;
            padding-top: 7px;
            color: #6b7280;
            font-size: 8px;
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1 class="title">
            LCMS — {{ $reportLabel }} Report
        </h1>

        <p class="subtitle">
            Authorized report generated from the current LCMS reporting scope.
        </p>
    </div>

    <table class="summary-table">
        <tr>
            <td>
                <span class="summary-label">
                    Report Type
                </span>

                <span class="summary-value">
                    {{ $dataset['report_type'] }}
                </span>
            </td>

            <td>
                <span class="summary-label">
                    Scope
                </span>

                <span class="summary-value">
                    {{ $dataset['scope']['scope_type'] ?? 'authorized' }}
                </span>
            </td>

            <td>
                <span class="summary-label">
                    Records
                </span>

                <span class="summary-value">
                    {{ $dataset['row_count'] }}
                </span>
            </td>

            <td>
                <span class="summary-label">
                    Generated At
                </span>

                <span class="summary-value">
                    {{ $generatedAt }}
                </span>
            </td>
        </tr>
    </table>

    <div class="section-title">
        Applied Filters
    </div>

    <div class="filters">
        @forelse ($dataset['filters'] as $key => $value)
            <span class="filter-item">
                <span class="filter-key">
                    {{ $key }}:
                </span>

                {{ is_scalar($value) || $value === null
            ? ($value ?? '—')
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
            </span>
        @empty
            No filters applied.
        @endforelse
    </div>

    <div class="section-title">
        Report Data
    </div>

    @if ($dataset['empty'])
        <div class="empty-state">
            No report records match the current authorized scope and filters.
        </div>
    @else
        <table class="report-table">
            <thead>
                <tr>
                    @foreach ($dataset['columns'] as $label)
                        <th>
                            {{ $label }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($dataset['rows'] as $row)
                    <tr>
                        @foreach (array_keys($dataset['columns']) as $columnKey)
                            @php
                                $value = $row[$columnKey] ?? null;
                            @endphp

                            <td>
                                @if ($value === null || $value === '')
                                    —
                                @elseif (is_bool($value))
                                    {{ $value ? 'Yes' : 'No' }}
                                @elseif (is_scalar($value))
                                    {{ $value }}
                                @else
                                        {{ json_encode(
                                        $value,
                                        JSON_UNESCAPED_UNICODE
                                        | JSON_UNESCAPED_SLASHES
                                    ) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Language Center Management System (LCMS)
    </div>
</body>

</html>