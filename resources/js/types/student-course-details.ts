export type CourseDetailsStatus = 'Active' | 'Completed' | 'Cancelled';

export type CourseInformationRow = {
    label: string;
    value: string;
};

export type QuickAttendanceData = {
    present: number;
    absent: number;
    late: number;
    rate: number;
};

export type CoursePaymentSummary = {
    currency: string;
    totalFees: number;
    discount: number;
    paid: number;
    remaining: number;
};

export type CourseSessionStatus = 'Upcoming' | 'Completed';

export type CourseSession = {
    id: number;
    date: string;
    day: string;
    time: string;
    room: string;
    status: CourseSessionStatus;
};

export type AttendanceStatus = 'Present' | 'Absent' | 'Late';

export type AttendanceLogEntry = {
    id: number;
    date: string;
    status: AttendanceStatus;
    note?: string;
};

export type InstallmentStatus = 'Paid' | 'Overdue' | 'Upcoming';

export type CourseInstallment = {
    id: number;
    amount: number;
    dueDate: string;
    status: InstallmentStatus;
    paidDate?: string;
    paymentMethod?: string;
    receiptHref?: string;
};

export type StudentCourseDetails = {
    id: number;
    title: string;
    status: CourseDetailsStatus;
    information: CourseInformationRow[];
    attendance: QuickAttendanceData;
    attendanceLog: AttendanceLogEntry[];
    payment: CoursePaymentSummary;
    installments: CourseInstallment[];
    sessions: CourseSession[];
};
