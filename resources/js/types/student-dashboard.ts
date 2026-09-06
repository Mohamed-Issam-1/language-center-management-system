export type DashboardStat = {
    label: string;
    value: string;
    tone: 'blue' | 'cyan' | 'red';
    icon: 'courses' | 'attendance' | 'paid' | 'outstanding';
};

export type DashboardCourse = {
    code: string;
    title: string;
    teacher: string;
    schedule: string;
    attendance: number;
    accent: 'indigo' | 'violet';
};

export type DashboardSession = {
    course: string;
    teacher: string;
    room: string;
    time: string;
};

export type FinancialSummaryData = {
    currency: string;
    totalFees: number;
    totalPaid: number;
    remaining: number;
    overdue: number;

    nextInstallment: number | null;
    nextInstallmentCourse: string | null;
    nextInstallmentDue: string | null;
};

export type StudentDashboardData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    greeting: {
        dateLabel: string;
        title: string;
    };

    stats: DashboardStat[];

    courses: DashboardCourse[];

    nextSession: DashboardSession | null;

    todaySchedule: DashboardSession[];

    todayScheduleLabel: string;

    attendance: {
        average: number;
        totalAbsent: number;
        warningText: string | null;
        maximumAllowedAbsences: number | null;
    };

    financial: FinancialSummaryData;
};