export type AttendanceStatus = 'Present' | 'Absent' | 'Late' | 'Excused';

export type AttendanceSummary = {
    averageRate: number;
    present: number;
    absent: number;
    late: number;
    excused: number;
    maximumAbsences: number;
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
