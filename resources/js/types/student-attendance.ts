export type AttendanceStatus =
    | 'Present'
    | 'Absent'
    | 'Late'
    | 'Excused';

export type AttendanceSummary = {
    averageRate: number;
    present: number;
    absent: number;
    late: number;
    excused: number;

    /**
     * Legacy/demo design value.
     * Real backend currently uses minimum attendance
     * percentage policy instead.
     */
    maximumAbsences?: number | null;
};

export type AttendanceAlertData = {
    title: string;
    message: string;
};

export type AttendanceCourseSummary = {
    id: number;
    courseName: string;
    rate: number;
    present: number;
    absent: number;
    late: number;
};

export type WeeklyAttendance = {
    week: string;
    present: number;
    absent: number;
};

export type AttendanceSessionRecord = {
    id: number;
    date: string;
    sortDate: string;
    day: string;
    course: string;
    status: AttendanceStatus;
    note: string;
};

export type StudentAttendancePageData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    summary: AttendanceSummary;

    alert: AttendanceAlertData | null;

    courses: AttendanceCourseSummary[];

    weekly: WeeklyAttendance[];

    records: AttendanceSessionRecord[];
};