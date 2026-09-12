import '../../../css/student-records.css';

import AttendanceAlert from '@/Features/Student/Attendance/AttendanceAlert';
import AttendanceByCourse from '@/Features/Student/Attendance/AttendanceByCourse';
import AttendanceStats from '@/Features/Student/Attendance/AttendanceStats';
import SessionLog from '@/Features/Student/Attendance/SessionLog';
import WeeklyAttendanceChart from '@/Features/Student/Attendance/WeeklyAttendanceChart';
import StudentLayout from '@/Layouts/StudentLayout';
import type {
    AttendanceAlertData,
    AttendanceCourseSummary,
    AttendanceSessionRecord,
    AttendanceSummary,
    StudentAttendancePageData,
    WeeklyAttendance,
} from '@/types/student-attendance';
import type { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/react';

const summary: AttendanceSummary = {
    averageRate: 92,
    present: 41,
    absent: 4,
    late: 2,
    excused: 0,
    maximumAbsences: 6,
};

const courseSummaries: AttendanceCourseSummary[] = [
    {
        id: 1,
        courseName: 'English – Intermediate',
        rate: 88,
        present: 22,
        absent: 3,
        late: 2,
    },
    {
        id: 2,
        courseName: 'French – Beginner',
        rate: 95,
        present: 19,
        absent: 1,
        late: 0,
    },
];

const weekly: WeeklyAttendance[] = [
    { week: 'W1', present: 2, absent: 0 },
    { week: 'W2', present: 2, absent: 0 },
    { week: 'W3', present: 1, absent: 1 },
    { week: 'W4', present: 2, absent: 0 },
    { week: 'W5', present: 2, absent: 0 },
    { week: 'W6', present: 1, absent: 1 },
    { week: 'W7', present: 2, absent: 0 },
    { week: 'W8', present: 2, absent: 0 },
];

const sessionRecords: AttendanceSessionRecord[] = [
    {
        id: 1,
        date: 'Nov 18, 2024',
        sortDate: '2024-11-18',
        day: 'Mon',
        course: 'English – Intermediate',
        status: 'Present',
        note: '',
    },
    {
        id: 2,
        date: 'Nov 13, 2024',
        sortDate: '2024-11-13',
        day: 'Mon',
        course: 'English – Intermediate',
        status: 'Present',
        note: '',
    },
    {
        id: 3,
        date: 'Nov 11, 2024',
        sortDate: '2024-11-11',
        day: 'Mon',
        course: 'English – Intermediate',
        status: 'Absent',
        note: 'No excuse submitted',
    },
    {
        id: 4,
        date: 'Nov 6, 2024',
        sortDate: '2024-11-06',
        day: 'Wed',
        course: 'English – Intermediate',
        status: 'Late',
        note: '',
    },
    {
        id: 5,
        date: 'Nov 4, 2024',
        sortDate: '2024-11-04',
        day: 'Mon',
        course: 'English – Intermediate',
        status: 'Present',
        note: '',
    },
    {
        id: 6,
        date: 'Oct 30, 2024',
        sortDate: '2024-10-30',
        day: 'Wed',
        course: 'English – Intermediate',
        status: 'Present',
        note: '',
    },
    {
        id: 7,
        date: 'Nov 14, 2024',
        sortDate: '2024-11-14',
        day: 'Tue',
        course: 'French – Beginner',
        status: 'Present',
        note: '',
    },
    {
        id: 8,
        date: 'Nov 12, 2024',
        sortDate: '2024-11-12',
        day: 'Tue',
        course: 'French – Beginner',
        status: 'Present',
        note: '',
    },
    {
        id: 9,
        date: 'Nov 7, 2024',
        sortDate: '2024-11-07',
        day: 'Thu',
        course: 'French – Beginner',
        status: 'Present',
        note: '',
    },
    {
        id: 10,
        date: 'Nov 5, 2024',
        sortDate: '2024-11-05',
        day: 'Tue',
        course: 'French – Beginner',
        status: 'Present',
        note: '',
    },
    {
        id: 11,
        date: 'Oct 31, 2024',
        sortDate: '2024-10-31',
        day: 'Thu',
        course: 'French – Beginner',
        status: 'Absent',
        note: '',
    },
    {
        id: 12,
        date: 'Oct 29, 2024',
        sortDate: '2024-10-29',
        day: 'Tue',
        course: 'French – Beginner',
        status: 'Present',
        note: '',
    },
];

const demoAlert: AttendanceAlertData = {
    title: 'Approaching Absence Limit',
    message:
        'You have 4 absences. The maximum allowed is 6. 2 absences remaining before limit is reached.',
};

const demoAttendanceData: StudentAttendancePageData = {
    student: {
        name: 'Mohammad Znaid',
        studentId: 'STU-2026-0842',
    },

    center: {
        name: 'Al-Hilal Language Center',
        branch: 'Gaza – Palestine',
    },

    summary,

    alert: demoAlert,

    courses: courseSummaries,

    weekly,

    records: sessionRecords,
};

type MyAttendancePageProps = PageProps & {
    studentAttendance?: StudentAttendancePageData;
};

export default function MyAttendance() {
    const page =
        usePage<MyAttendancePageProps>();

    const demoMode =
        page.url.startsWith('/demo');

    const data =
        demoMode
            ? demoAttendanceData
            : page.props.studentAttendance;

    if (!data) {
        throw new Error(
            'Student attendance data was not provided by Laravel.',
        );
    }

    return (
        <StudentLayout
            studentName={data.student.name}
            studentId={data.student.studentId}
            centerName={data.center.name}
            branchName={data.center.branch}
            pageTitle="My Attendance"
            activeNav="attendance"
            fluid
        >
            <Head title="My Attendance" />

            <div className="student-records-page records-stack">
                <AttendanceAlert
                    alert={data.alert}
                />

                <AttendanceStats
                    summary={data.summary}
                />

                <AttendanceByCourse
                    courses={data.courses}
                />

                <WeeklyAttendanceChart
                    data={data.weekly}
                />

                <SessionLog
                    records={data.records}
                />
            </div>
        </StudentLayout>
    );
}