<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2
                class="text-lg font-semibold text-gray-950 dark:text-white"
            >
                Reports
            </h2>

            <p
                class="mt-1 text-sm text-gray-500 dark:text-gray-400"
            >
                View authorized operational reports for the current scope.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach (
    $this->availableReportTypes()
    as $type => $label
)
                <button
                    type="button"
                    wire:click="selectReport('{{ $type }}')"
                    wire:key="report-type-{{ $type }}"
                    @class([
        'rounded-lg px-4 py-2 text-sm font-medium transition',
        'bg-primary-600 text-white shadow-sm' =>
            $reportType === $type,
        'bg-white text-gray-700 ring-1 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10' =>
            $reportType !== $type,
    ])
                >
                    {{ $label }}
                </button>
            @endforeach

            <button
                type="button"
                wire:click="refreshReport"
                wire:loading.attr="disabled"
                class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10"
            >
                Refresh
            </button>

            <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv"
                class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10">
                Export CSV
                <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" wire:target="exportPdf"
                    class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10">
                    Export PDF
                </button>
            </button>
        </div>

        @if (count($this->filterDefinitions()) > 0)
            <form
                wire:submit="applyFilters"
                class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
            >
                <div class="mb-4">
                    <h3
                        class="font-semibold text-gray-950 dark:text-white"
                    >
                        Filters
                    </h3>

                    <p
                        class="mt-1 text-sm text-gray-500 dark:text-gray-400"
                    >
                        Narrow the current report without changing its authorized scope.
                    </p>
                </div>

                <div
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                >
                    @foreach (
        $this->filterDefinitions()
        as $key => $definition
    )
                        <div wire:key="report-filter-{{ $reportType }}-{{ $key }}">
                            <label
                                for="report-filter-{{ $key }}"
                                class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200"
                            >
                                {{ $definition['label'] }}
                            </label>

                            @if ($definition['type'] === 'select')
                                <select
                                    id="report-filter-{{ $key }}"
                                    wire:model="filters.{{ $key }}"
                                    class="block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
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
                                <input
                                    id="report-filter-{{ $key }}"
                                    type="{{ $definition['type'] }}"
                                    wire:model="filters.{{ $key }}"
                                    @if ($definition['type'] === 'number')
                                        min="1"
                                        step="1"
                                    @endif
                                    @if (isset($definition['maxlength']))
                                        maxlength="{{ $definition['maxlength'] }}"
                                    @endif
                                    @if (isset($definition['placeholder']))
                                        placeholder="{{ $definition['placeholder'] }}"
                                    @endif
                                    class="block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                >
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($filterError !== null)
                    <div
                        class="mt-4 rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300"
                    >
                        {{ $filterError }}
                    </div>
                @endif

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-500"
                    >
                        Apply Filters
                    </button>

                    <button
                        type="button"
                        wire:click="clearFilters"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10"
                    >
                        Clear Filters
                    </button>
                </div>
            </form>
        @endif

        @if (!empty($dataset))
            <div class="grid gap-4 md:grid-cols-3">
                <div
                    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                >
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Report Type
                    </div>

                    <div class="mt-1 font-semibold text-gray-950 dark:text-white">
                        {{ ucfirst($dataset['report_type']) }}
                    </div>
                </div>

                <div
                    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                >
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Scope
                    </div>

                    <div class="mt-1 font-semibold text-gray-950 dark:text-white">
                        {{ ucfirst($dataset['scope']['scope_type'] ?? 'unknown') }}
                    </div>
                </div>

                <div
                    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                >
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Records
                    </div>

                    <div class="mt-1 font-semibold text-gray-950 dark:text-white">
                        {{ $dataset['row_count'] ?? 0 }}
                    </div>
                </div>
            </div>

            @if ($dataset['empty'] ?? true)
                <div
                    class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-white/20 dark:bg-white/5"
                >
                    <div class="font-medium text-gray-950 dark:text-white">
                        No report records
                    </div>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        No report records match the current authorized scope and filters.
                    </p>
                </div>
            @else
                <div
                    class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                >
                    <div class="overflow-x-auto">
                        <table
                            class="min-w-full divide-y divide-gray-200 dark:divide-white/10"
                        >
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    @foreach (
            $dataset['columns']
            as $columnLabel
        )
                                        <th
                                            scope="col"
                                            class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300"
                                        >
                                            {{ $columnLabel }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>

                            <tbody
                                class="divide-y divide-gray-200 dark:divide-white/10"
                            >
                                @foreach (
            $dataset['rows']
            as $rowIndex => $row
        )
                                    <tr wire:key="report-row-{{ $reportType }}-{{ $rowIndex }}">
                                        @foreach (
                $dataset['columns']
                as $columnKey => $columnLabel
            )
                                            <td
                                                class="whitespace-nowrap px-4 py-3 text-sm text-gray-700 dark:text-gray-200"
                                            >
                                                @php
                $value =
                    $row[$columnKey]
                    ?? null;
                                                @endphp

                                                @if ($value === null || $value === '')
                                                    <span class="text-gray-400">
                                                        —
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
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>