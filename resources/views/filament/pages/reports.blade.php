<x-filament-panels::page>
    <div class="lcms-reports">
        <section class="lcms-reports__intro">
            <div>
                <h2 class="lcms-reports__title">
                    Reports
                </h2>

                <p class="lcms-reports__description">
                    View authorized operational reports for the current scope.
                </p>
            </div>

            <div class="lcms-reports__tabs">
                @foreach (
                        $this->availableReportTypes()
                        as $type => $label
                    )
                    <button type="button" wire:click="selectReport('{{ $type }}')" wire:key="report-type-{{ $type }}"
                        class="lcms-report-tab {{ $reportType === $type ? 'is-active' : '' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </section>

        <section class="lcms-report-toolbar">
            <button type="button" wire:click="refreshReport" wire:loading.attr="disabled"
                class="lcms-report-button lcms-report-button--secondary">
                Refresh
            </button>

            <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv"
                class="lcms-report-button lcms-report-button--secondary">
                Export CSV
            </button>

            <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" wire:target="exportPdf"
                class="lcms-report-button lcms-report-button--primary">
                Export PDF
            </button>
        </section>

        @if (count($this->filterDefinitions()) > 0)
            <form wire:submit="applyFilters" class="lcms-report-card">
                <div class="lcms-report-card__header">
                    <div>
                        <h3 class="lcms-report-card__title">
                            Filters
                        </h3>

                        <p class="lcms-report-card__description">
                            Narrow the current report without changing its authorized scope.
                        </p>
                    </div>
                </div>

                <div class="lcms-report-filters">
                    @foreach (
                            $this->filterDefinitions()
                            as $key => $definition
                        )
                        <div class="lcms-report-field" wire:key="report-filter-{{ $reportType }}-{{ $key }}">
                            <label for="report-filter-{{ $key }}" class="lcms-report-label">
                                {{ $definition['label'] }}
                            </label>

                            @if ($definition['type'] === 'select')
                                <select id="report-filter-{{ $key }}" wire:model="filters.{{ $key }}" class="lcms-report-control">
                                    <option value="">
                                        All
                                    </option>

                                    @foreach (
                                            $definition['options']
                                            as $value => $label
                                        )
                                        <option value="{{ $value }}">
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input id="report-filter-{{ $key }}" type="{{ $definition['type'] }}"
                                    wire:model="filters.{{ $key }}" @if ($definition['type'] === 'number') min="1" step="1" @endif
                                   @if (isset($definition['maxlength'])) maxlength="{{ $definition['maxlength'] }}" @endif
                                        @if (isset($definition['placeholder'])) placeholder="{{ $definition['placeholder'] }}" @endif
                                    class="lcms-report-control">
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($filterError !== null)
                    <div class="lcms-report-error">
                        {{ $filterError }}
                    </div>
                @endif

                <div class="lcms-report-form-actions">
                    <button type="submit" wire:loading.attr="disabled"
                        class="lcms-report-button lcms-report-button--primary">
                        Apply Filters
                    </button>

                    <button type="button" wire:click="clearFilters" wire:loading.attr="disabled"
                        class="lcms-report-button lcms-report-button--secondary">
                        Clear Filters
                    </button>
                </div>
            </form>
        @endif

        @if (!empty($dataset))
            <div class="lcms-report-stats">
                <article class="lcms-report-stat">
                    <span class="lcms-report-stat__label">
                        Report Type
                    </span>

                    <strong class="lcms-report-stat__value">
                        {{ ucfirst($dataset['report_type']) }}
                    </strong>
                </article>

                <article class="lcms-report-stat">
                    <span class="lcms-report-stat__label">
                        Scope
                    </span>

                    <strong class="lcms-report-stat__value">
                        {{ ucfirst($dataset['scope']['scope_type'] ?? 'unknown') }}
                    </strong>
                </article>

                <article class="lcms-report-stat">
                    <span class="lcms-report-stat__label">
                        Records
                    </span>

                    <strong class="lcms-report-stat__value">
                        {{ $dataset['row_count'] ?? 0 }}
                    </strong>
                </article>
            </div>

            @if ($dataset['empty'] ?? true)
                <div class="lcms-report-empty">
                    <div class="lcms-report-empty__title">
                        No report records
                    </div>

                    <p>
                        No report records match the current authorized scope and filters.
                    </p>
                </div>
            @else
                <section class="lcms-report-table-card">
                    <div class="lcms-report-table-heading">
                        <div>
                            <h3>
                                {{ ucfirst($dataset['report_type']) }} Report
                            </h3>

                            <p>
                                {{ $dataset['row_count'] ?? 0 }}
                                {{ ($dataset['row_count'] ?? 0) === 1 ? 'record' : 'records' }}
                            </p>
                        </div>
                    </div>

                    <div class="lcms-report-table-scroll">
                        <table class="lcms-report-table">
                            <thead>
                                <tr>
                                    @foreach (
                                            $dataset['columns']
                                            as $columnLabel
                                        )
                                        <th scope="col">
                                            {{ $columnLabel }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>

                            <tbody>
                                @foreach (
                                        $dataset['rows']
                                        as $rowIndex => $row
                                    )
                                    <tr wire:key="report-row-{{ $reportType }}-{{ $rowIndex }}">
                                        @foreach (
                                                $dataset['columns']
                                                as $columnKey => $columnLabel
                                            )
                                            <td>
                                                @php
                                                    $value =
                                                        $row[$columnKey]
                                                        ?? null;
                                                @endphp

                                                @if ($value === null || $value === '')
                                                    <span class="lcms-report-empty-value">
                                                        &mdash;
                                                    </span>
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>