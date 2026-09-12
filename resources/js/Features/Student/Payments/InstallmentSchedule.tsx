import RecordsToolbar from '@/Features/Student/Common/RecordsToolbar';
import TablePagination from '@/Features/Student/Common/TablePagination';
import { exportCsv } from '@/Features/Student/Common/exportCsv';
import type {
    PaymentInstallmentRecord,
    PaymentStatus,
} from '@/types/student-payments';
import {
    Link,
} from '@inertiajs/react';
import {
    ChevronsUpDown,
} from 'lucide-react';
import {
    useMemo,
    useState,
} from 'react';

type SortKey =
    | 'receiptNo'
    | 'course'
    | 'amount'
    | 'dueDate'
    | 'paidDate'
    | 'method'
    | 'status';

function statusClass(
    status: PaymentStatus,
) {
    return status.toLowerCase();
}

export default function InstallmentSchedule({
    records,
    currency,
}: {
    records: PaymentInstallmentRecord[];
    currency: string;
}) {
    const [
        search,
        setSearch,
    ] = useState('');

    const [
        sortKey,
        setSortKey,
    ] =
        useState<SortKey>(
            'dueDate'
        );

    const [
        ascending,
        setAscending,
    ] =
        useState(true);

    const [
        selected,
        setSelected,
    ] =
        useState<number[]>([]);

    const filtered =
        useMemo(() => {
            const query =
                search
                    .trim()
                    .toLowerCase();

            const result =
                records.filter(
                    (record) =>
                        [
                            record.receiptNo ??
                                '—',

                            record.course,

                            record.amount.toString(),

                            record.dueDate,

                            record.paidDate ??
                                '—',

                            record.method ??
                                '—',

                            record.status,
                        ].some(
                            (
                                value,
                            ) =>
                                value
                                    .toLowerCase()
                                    .includes(
                                        query,
                                    ),
                        ),
                );

            return [
                ...result,
            ].sort(
                (a, b) => {
                    let aValue =
                        '';

                    let bValue =
                        '';

                    if (
                        sortKey ===
                        'amount'
                    ) {
                        const compare =
                            a.amount -
                            b.amount;

                        return ascending
                            ? compare
                            : -compare;
                    }

                    if (
                        sortKey ===
                        'dueDate'
                    ) {
                        aValue =
                            a.dueDateSort;

                        bValue =
                            b.dueDateSort;
                    } else {
                        aValue =
                            String(
                                a[
                                    sortKey
                                ] ?? '',
                            );

                        bValue =
                            String(
                                b[
                                    sortKey
                                ] ?? '',
                            );
                    }

                    const compare =
                        aValue.localeCompare(
                            bValue,
                        );

                    return ascending
                        ? compare
                        : -compare;
                },
            );
        }, [
            ascending,
            records,
            search,
            sortKey,
        ]);

    const allSelected =
        filtered.length > 0 &&
        filtered.every(
            (record) =>
                selected.includes(
                    record.id,
                ),
        );

    const toggleAll = () => {
        const ids =
            filtered.map(
                (record) =>
                    record.id,
            );

        if (allSelected) {
            setSelected(
                (current) =>
                    current.filter(
                        (id) =>
                            !ids.includes(
                                id,
                            ),
                    ),
            );

            return;
        }

        setSelected(
            (current) =>
                Array.from(
                    new Set([
                        ...current,
                        ...ids,
                    ]),
                ),
        );
    };

    const toggleRow = (
        id: number,
    ) => {
        setSelected(
            (current) =>
                current.includes(id)
                    ? current.filter(
                          (item) =>
                              item !==
                              id,
                      )
                    : [
                          ...current,
                          id,
                      ],
        );
    };

    const changeSort = (
        key: SortKey,
    ) => {
        if (
            sortKey === key
        ) {
            setAscending(
                (current) =>
                    !current,
            );
        } else {
            setSortKey(key);
            setAscending(true);
        }
    };

    const exportRows = () => {
        exportCsv(
            'installment-schedule.csv',
            [
                'Receipt No.',
                'Course',
                'Amount',
                'Due Date',
                'Paid Date',
                'Method',
                'Status',
            ],
            filtered.map(
                (record) => [
                    record.receiptNo ??
                        '—',

                    record.course,

                    `${currency} ${record.amount}`,

                    record.dueDate,

                    record.paidDate ??
                        '—',

                    record.method ??
                        '—',

                    record.status,
                ],
            ),
        );
    };

    return (
        <section className="records-card">
            <div className="records-table-header">
                <h2 className="records-heading">
                    Installment Schedule
                </h2>

                <p className="records-subtitle">
                    All installments
                    across your enrolled
                    courses
                </p>
            </div>

            <RecordsToolbar
                search={search}
                onSearchChange={
                    setSearch
                }
                onExport={
                    exportRows
                }
            />

            <div className="records-table-scroll">
                <table className="records-table payment-table">
                    <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    className="records-checkbox"
                                    checked={
                                        allSelected
                                    }
                                    onChange={
                                        toggleAll
                                    }
                                    aria-label="Select all installments"
                                />
                            </th>

                            {(
                                [
                                    [
                                        'receiptNo',
                                        'Receipt No.',
                                    ],
                                    [
                                        'course',
                                        'Course',
                                    ],
                                    [
                                        'amount',
                                        'Amount',
                                    ],
                                    [
                                        'dueDate',
                                        'Due Date',
                                    ],
                                    [
                                        'paidDate',
                                        'Paid Date',
                                    ],
                                    [
                                        'method',
                                        'Method',
                                    ],
                                    [
                                        'status',
                                        'Status',
                                    ],
                                ] as const
                            ).map(
                                ([
                                    key,
                                    label,
                                ]) => (
                                    <th
                                        key={
                                            key
                                        }
                                    >
                                        <button
                                            type="button"
                                            className="records-sort-button"
                                            onClick={() =>
                                                changeSort(
                                                    key,
                                                )
                                            }
                                        >
                                            {
                                                label
                                            }

                                            <ChevronsUpDown
                                                size={
                                                    11
                                                }
                                            />
                                        </button>
                                    </th>
                                ),
                            )}

                            <th />
                        </tr>
                    </thead>

                    <tbody>
                        {filtered.map(
                            (
                                record,
                            ) => {
                                const rowSelected =
                                    selected.includes(
                                        record.id,
                                    );

                                return (
                                    <tr
                                        key={
                                            record.id
                                        }
                                        className={
                                            rowSelected
                                                ? 'bg-[#e7edf8]'
                                                : ''
                                        }
                                    >
                                        <td>
                                            <input
                                                type="checkbox"
                                                className="records-checkbox"
                                                checked={
                                                    rowSelected
                                                }
                                                onChange={() =>
                                                    toggleRow(
                                                        record.id,
                                                    )
                                                }
                                                aria-label={`Select installment ${record.id}`}
                                            />
                                        </td>

                                        <td
                                            className={
                                                record.receiptNo
                                                    ? ''
                                                    : 'payment-receipt-muted'
                                            }
                                        >
                                            {record.receiptNo ??
                                                '—'}
                                        </td>

                                        <td>
                                            {
                                                record.course
                                            }
                                        </td>

                                        <td className="payment-amount">
                                            {
                                                currency
                                            }{' '}
                                            {record.amount.toLocaleString(
                                                'en-US',
                                            )}
                                        </td>

                                        <td>
                                            {
                                                record.dueDate
                                            }
                                        </td>

                                        <td>
                                            {record.paidDate ??
                                                '—'}
                                        </td>

                                        <td>
                                            {record.method ??
                                                '—'}
                                        </td>

                                        <td>
                                            <span
                                                className={`records-status ${statusClass(
                                                    record.status,
                                                )}`}
                                            >
                                                {
                                                    record.status
                                                }
                                            </span>
                                        </td>

                                        <td>
                                            {record.receiptNo &&
                                                record.receiptHref && (
                                                    <Link
                                                        href={
                                                            record.receiptHref
                                                        }
                                                        className="records-receipt"
                                                    >
                                                        Receipt
                                                        →
                                                    </Link>
                                                )}
                                        </td>
                                    </tr>
                                );
                            },
                        )}
                    </tbody>

                    <tfoot>
                        <tr>
                            <th />
                            <th>
                                Receipt No.
                            </th>
                            <th>
                                Course
                            </th>
                            <th>
                                Amount
                            </th>
                            <th>
                                Due Date
                            </th>
                            <th>
                                Paid Date
                            </th>
                            <th>
                                Method
                            </th>
                            <th>
                                Status
                            </th>
                            <th />
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div className="records-table-footer">
                <span>
                    Showing{' '}
                    <strong>
                        {filtered.length ===
                        0
                            ? '0'
                            : `1–${filtered.length}`}
                    </strong>{' '}
                    of{' '}
                    <strong>
                        {
                            filtered.length
                        }
                    </strong>{' '}
                    records
                </span>

                <TablePagination
                    page={1}
                    pageCount={1}
                    onPageChange={() =>
                        undefined
                    }
                />
            </div>
        </section>
    );
}