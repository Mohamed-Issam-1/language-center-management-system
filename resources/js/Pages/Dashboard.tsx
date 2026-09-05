import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

type StatusCounts = Record<string, number>;

interface EnrollmentSummary {
    total: number;
    by_status: StatusCounts;
}

interface ClassSummary {
    total: number;
    by_status: StatusCounts;
}

interface AttendanceSummary {
    breakdown_count: number;
    recorded_sessions: number;
    attendance_equivalent: string;
    absence_equivalent: string;
    attendance_percentage: string | null;
    absence_percentage: string | null;
}

interface StudentBalanceCurrency {
    obligation: string;
    paid: string;
    balance: string;
}

interface StudentBalanceData {
    center_id: number;
    student_id: number;
    totals: Record<string, StudentBalanceCurrency>;
    installments: Array<Record<string, unknown>>;
}

interface FinanceSummary {
    report_type: 'student_balance' | 'operational';
    data: StudentBalanceData | Record<string, unknown>;
}

interface DashboardScope {
    actor_id: number;
    role: string;
    scope_type: string;
    center_id: number | null;
    branch_id: number | null;
    subject_id: number | null;
}

interface DashboardPayload {
    scope: DashboardScope;
    enrollments: EnrollmentSummary | null;
    classes: ClassSummary | null;
    attendance: AttendanceSummary | null;
    finance: FinanceSummary | null;
}

interface DashboardProps {
    dashboard: DashboardPayload;
}

interface StatCardProps {
    title: string;
    value: string | number;
    description?: string;
}

function StatCard({
    title,
    value,
    description,
}: StatCardProps) {
    return (
        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <p className="text-sm font-medium text-gray-500">
                {title}
            </p>

            <p className="mt-2 text-3xl font-semibold tracking-tight text-gray-900">
                {value}
            </p>

            {description && (
                <p className="mt-2 text-sm text-gray-500">
                    {description}
                </p>
            )}
        </div>
    );
}

function roleLabel(role: string): string {
    if (role === 'teacher') {
        return 'Teacher';
    }

    if (role === 'student') {
        return 'Student';
    }

    return role;
}

export default function Dashboard({
    dashboard,
}: DashboardProps) {
    const {
        scope,
        enrollments,
        classes,
        attendance,
        finance,
    } = dashboard;

    const studentFinance =
        finance?.report_type === 'student_balance'
            ? (finance.data as StudentBalanceData)
            : null;

    const financialTotals =
        studentFinance?.totals ?? {};

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Dashboard
                    </h2>

                    <p className="mt-1 text-sm text-gray-500">
                        {roleLabel(scope.role)} overview
                    </p>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="py-8 sm:py-12">
                <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
                    <section>
                        <div className="mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">
                                Academic Overview
                            </h3>

                            <p className="mt-1 text-sm text-gray-500">
                                A summary of your current academic activity.
                            </p>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {enrollments && (
                                <>
                                    <StatCard
                                        title="Total Enrollments"
                                        value={enrollments.total}
                                    />

                                    <StatCard
                                        title="Active Enrollments"
                                        value={
                                            enrollments.by_status.active ??
                                            0
                                        }
                                    />
                                </>
                            )}

                            {classes && (
                                <>
                                    <StatCard
                                        title="Total Classes"
                                        value={classes.total}
                                    />

                                    <StatCard
                                        title="Active Classes"
                                        value={
                                            classes.by_status.active ??
                                            0
                                        }
                                    />
                                </>
                            )}
                        </div>
                    </section>

                    {attendance && (
                        <section>
                            <div className="mb-4">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Attendance
                                </h3>

                                <p className="mt-1 text-sm text-gray-500">
                                    Attendance information from recorded sessions.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <StatCard
                                    title="Attendance Rate"
                                    value={
                                        attendance.attendance_percentage ===
                                            null
                                            ? '—'
                                            : `${attendance.attendance_percentage}%`
                                    }
                                    description={
                                        attendance.attendance_percentage ===
                                            null
                                            ? 'No attendance has been recorded yet.'
                                            : undefined
                                    }
                                />

                                <StatCard
                                    title="Recorded Sessions"
                                    value={
                                        attendance.recorded_sessions
                                    }
                                />

                                <StatCard
                                    title="Attendance Equivalent"
                                    value={
                                        attendance.attendance_equivalent
                                    }
                                />

                                <StatCard
                                    title="Absence Equivalent"
                                    value={
                                        attendance.absence_equivalent
                                    }
                                />
                            </div>
                        </section>
                    )}

                    {scope.role === 'student' &&
                        studentFinance && (
                            <section>
                                <div className="mb-4">
                                    <h3 className="text-lg font-semibold text-gray-900">
                                        Financial Summary
                                    </h3>

                                    <p className="mt-1 text-sm text-gray-500">
                                        Your current financial position.
                                    </p>
                                </div>

                                {Object.keys(
                                    financialTotals,
                                ).length === 0 ? (
                                    <div className="rounded-lg bg-white p-6 text-sm text-gray-500 shadow-sm ring-1 ring-gray-200">
                                        No financial obligations are currently recorded.
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        {Object.entries(
                                            financialTotals,
                                        ).map(
                                            ([
                                                currency,
                                                totals,
                                            ]) => (
                                                <div
                                                    key={
                                                        currency
                                                    }
                                                    className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                                                >
                                                    <div className="mb-5 flex items-center justify-between gap-4">
                                                        <div>
                                                            <p className="text-sm font-medium text-gray-500">
                                                                Currency
                                                            </p>

                                                            <p className="mt-1 text-lg font-semibold text-gray-900">
                                                                {
                                                                    currency
                                                                }
                                                            </p>
                                                        </div>

                                                        <div className="text-right">
                                                            <p className="text-sm font-medium text-gray-500">
                                                                Balance
                                                            </p>

                                                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                                                {
                                                                    totals.balance
                                                                }{' '}
                                                                {
                                                                    currency
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div className="grid gap-4 border-t border-gray-100 pt-5 sm:grid-cols-2">
                                                        <div>
                                                            <p className="text-sm text-gray-500">
                                                                Total Obligation
                                                            </p>

                                                            <p className="mt-1 font-medium text-gray-900">
                                                                {
                                                                    totals.obligation
                                                                }{' '}
                                                                {
                                                                    currency
                                                                }
                                                            </p>
                                                        </div>

                                                        <div>
                                                            <p className="text-sm text-gray-500">
                                                                Paid
                                                            </p>

                                                            <p className="mt-1 font-medium text-gray-900">
                                                                {
                                                                    totals.paid
                                                                }{' '}
                                                                {
                                                                    currency
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                            </section>
                        )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}