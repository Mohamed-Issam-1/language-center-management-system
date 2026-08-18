import RecordsToolbar from '@/Features/Student/Common/RecordsToolbar';
import TablePagination from '@/Features/Student/Common/TablePagination';
import { exportCsv } from '@/Features/Student/Common/exportCsv';
import type {
    AttendanceSessionRecord,
    AttendanceStatus,
} from '@/types/student-attendance';
import { ChevronsUpDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type SortKey = 'date' | 'day' | 'course' | 'status';
type SortDirection = 'asc' | 'desc';

const PAGE_SIZE = 8;

function statusClass(status: AttendanceStatus) {
    return status.toLowerCase();
}

export default function SessionLog({
    records,
}: {
    records: AttendanceSessionRecord[];
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [sortKey, setSortKey] = useState<SortKey>('date');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('desc');

    const filtered = useMemo(() => {
        const query = search.trim().toLowerCase();

        const result = records.filter((record) =>
            [
                record.date,
                record.day,
                record.course,
                record.status,
                record.note,
            ].some((value) =>
                value.toLowerCase().includes(query),
            ),
        );

        return [...result].sort((a, b) => {
            const aValue =
                sortKey === 'date'
                    ? a.sortDate
                    : a[sortKey].toLowerCase();
            const bValue =
                sortKey === 'date'
                    ? b.sortDate
                    : b[sortKey].toLowerCase();

            const compare = aValue.localeCompare(bValue);

            return sortDirection === 'asc' ? compare : -compare;
        });
    }, [records, search, sortDirection, sortKey]);

    const pageCount = Math.max(
        1,
        Math.ceil(filtered.length / PAGE_SIZE),
    );

    useEffect(() => {
        setPage(1);
    }, [search]);

    useEffect(() => {
        if (page > pageCount) {
            setPage(pageCount);
        }
    }, [page, pageCount]);

    const startIndex = (page - 1) * PAGE_SIZE;
    const pageRows = filtered.slice(
        startIndex,
        startIndex + PAGE_SIZE,
    );

    const changeSort = (key: SortKey) => {
        if (key === sortKey) {
            setSortDirection((current) =>
                current === 'asc' ? 'desc' : 'asc',
            );
            return;
        }

        setSortKey(key);
        setSortDirection('asc');
    };

    const exportRows = () => {
        exportCsv(
            'attendance-session-log.csv',
            ['Date', 'Day', 'Course', 'Status', 'Note'],
            filtered.map((record) => [
                record.date,
                record.day,
                record.course,
                record.status,
                record.note || '—',
            ]),
        );
    };

    const showingFrom =
        filtered.length === 0 ? 0 : startIndex + 1;
    const showingTo = Math.min(
        startIndex + PAGE_SIZE,
        filtered.length,
    );

    return (
        <section className="records-card">
            <div className="records-table-header">
                <h2 className="records-heading">Session Log</h2>
                <p className="records-subtitle">
                    All attendance records across enrolled courses
                </p>
            </div>

            <RecordsToolbar
                search={search}
                onSearchChange={setSearch}
                onExport={exportRows}
            />

            <div className="records-table-scroll">
                <table className="records-table attendance-table">
                    <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    className="records-checkbox"
                                    aria-label="Select all rows"
                                />
                            </th>
                            {(
                                [
                                    ['date', 'Date'],
                                    ['day', 'Day'],
                                    ['course', 'Course'],
                                    ['status', 'Status'],
                                ] as const
                            ).map(([key, label]) => (
                                <th key={key}>
                                    <button
                                        type="button"
                                        className="records-sort-button"
                                        onClick={() =>
                                            changeSort(key)
                                        }
                                    >
                                        {label}
                                        <ChevronsUpDown size={11} />
                                    </button>
                                </th>
                            ))}
                            <th>Note</th>
                        </tr>
                    </thead>

                    <tbody>
                        {pageRows.map((record) => (
                            <tr key={record.id}>
                                <td>
                                    <input
                                        type="checkbox"
                                        className="records-checkbox"
                                        aria-label={`Select ${record.date}`}
                                    />
                                </td>
                                <td>{record.date}</td>
                                <td>{record.day}</td>
                                <td>{record.course}</td>
                                <td>
                                    <span
                                        className={`records-status ${statusClass(
                                            record.status,
                                        )}`}
                                    >
                                        {record.status}
                                    </span>
                                </td>
                                <td>{record.note || '—'}</td>
                            </tr>
                        ))}
                    </tbody>

                    <tfoot>
                        <tr>
                            <th />
                            <th>Date</th>
                            <th>Day</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th>Note</th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div className="records-table-footer">
                <span>
                    Showing <strong>{showingFrom}–{showingTo}</strong>{' '}
                    of <strong>{filtered.length}</strong> records
                </span>

                <TablePagination
                    page={page}
                    pageCount={pageCount}
                    onPageChange={setPage}
                />
            </div>
        </section>
    );
}
