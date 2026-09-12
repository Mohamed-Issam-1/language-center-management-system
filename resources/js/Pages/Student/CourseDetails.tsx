import AttendanceTab from '@/Features/Student/CourseDetails/AttendanceTab';
import CourseDetailsHeader from '@/Features/Student/CourseDetails/CourseDetailsHeader';
import CourseOverview from '@/Features/Student/CourseDetails/CourseOverview';
import CourseTabs, {
    type CourseDetailsTab,
} from '@/Features/Student/CourseDetails/CourseTabs';
import PaymentsTab from '@/Features/Student/CourseDetails/PaymentsTab';
import ScheduleTab from '@/Features/Student/CourseDetails/ScheduleTab';
import StudentLayout from '@/Layouts/StudentLayout';
import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { PageProps } from '@/types';
import type {
    StudentCourseDetails,
    StudentCourseDetailsPageData,
} from '@/types/student-course-details';

const courses: Record<number, StudentCourseDetails> = {
    1: {
        id: 1,
        title: 'English – Intermediate',
        status: 'Active',
        information: [
            { label: 'Course Name', value: 'English – Intermediate' },
            { label: 'Level', value: 'B2 Intermediate' },
            { label: 'Section', value: 'ENG-B2-03' },
            { label: 'Teacher', value: 'Mr. Hussam Al-Attar' },
            { label: 'Branch', value: 'Riyadh – Main Branch' },
            { label: 'Room', value: 'Room 204' },
            { label: 'Total Hours', value: '48 hrs' },
            { label: 'Duration', value: 'Sep 5, 2024 – Dec 20, 2024' },
            { label: 'Schedule', value: 'Mon, Wed · 5:00 – 7:00 PM' },
            { label: 'Language', value: 'English' },
        ],
        attendance: {
            present: 22,
            absent: 3,
            late: 2,
            rate: 88,
        },
        attendanceLog: [
            { id: 1, date: 'Nov 18, 2024', status: 'Present' },
            { id: 2, date: 'Nov 13, 2024', status: 'Present' },
            {
                id: 3,
                date: 'Nov 11, 2024',
                status: 'Absent',
                note: 'No excuse submitted',
            },
            { id: 4, date: 'Nov 6, 2024', status: 'Late' },
            { id: 5, date: 'Nov 4, 2024', status: 'Present' },
            { id: 6, date: 'Oct 30, 2024', status: 'Present' },
            { id: 7, date: 'Oct 28, 2024', status: 'Present' },
            { id: 8, date: 'Oct 23, 2024', status: 'Late' },
        ],
        payment: {
            currency: 'SAR',
            totalFees: 1800,
            discount: 100,
            paid: 1200,
            remaining: 500,
        },
        installments: [
            {
                id: 1,
                amount: 600,
                dueDate: 'Sep 5, 2024',
                status: 'Paid',
                paidDate: 'Sep 5, 2024',
                paymentMethod: 'Cash',
                receiptHref: '#',
            },
            {
                id: 2,
                amount: 600,
                dueDate: 'Oct 5, 2024',
                status: 'Paid',
                paidDate: 'Oct 8, 2024',
                paymentMethod: 'Bank Transfer',
                receiptHref: '#',
            },
            {
                id: 3,
                amount: 600,
                dueDate: 'Nov 5, 2024',
                status: 'Overdue',
            },
        ],
        sessions: [
            {
                id: 1,
                date: 'Nov 20, 2024',
                day: 'Wed',
                time: '5:00 PM – 7:00 PM',
                room: 'Room 204',
                status: 'Upcoming',
            },
            {
                id: 2,
                date: 'Nov 25, 2024',
                day: 'Mon',
                time: '5:00 PM – 7:00 PM',
                room: 'Room 204',
                status: 'Upcoming',
            },
            {
                id: 3,
                date: 'Nov 18, 2024',
                day: 'Mon',
                time: '5:00 PM – 7:00 PM',
                room: 'Room 204',
                status: 'Completed',
            },
            {
                id: 4,
                date: 'Nov 13, 2024',
                day: 'Wed',
                time: '5:00 PM – 7:00 PM',
                room: 'Room 204',
                status: 'Completed',
            },
        ],
    },

    2: {
        id: 2,
        title: 'French – Beginner',
        status: 'Active',
        information: [
            { label: 'Course Name', value: 'French – Beginner' },
            { label: 'Level', value: 'A1 Beginner' },
            { label: 'Section', value: 'FRE-A1-01' },
            { label: 'Teacher', value: 'Ms. Leila Mansouri' },
            { label: 'Branch', value: 'Riyadh – Main Branch' },
            { label: 'Room', value: 'Room 108' },
            { label: 'Total Hours', value: '40 hrs' },
            { label: 'Duration', value: 'Oct 1, 2024 – Dec 15, 2024' },
            { label: 'Schedule', value: 'Tue, Thu · 6:00 – 8:00 PM' },
            { label: 'Language', value: 'French' },
        ],
        attendance: {
            present: 20,
            absent: 1,
            late: 0,
            rate: 95,
        },
        attendanceLog: [
            { id: 1, date: 'Nov 14, 2024', status: 'Present' },
            { id: 2, date: 'Nov 12, 2024', status: 'Present' },
            { id: 3, date: 'Nov 7, 2024', status: 'Present' },
            { id: 4, date: 'Nov 5, 2024', status: 'Present' },
        ],
        payment: {
            currency: 'SAR',
            totalFees: 1100,
            discount: 0,
            paid: 700,
            remaining: 400,
        },
        installments: [
            {
                id: 1,
                amount: 700,
                dueDate: 'Oct 1, 2024',
                status: 'Paid',
                paidDate: 'Oct 1, 2024',
                paymentMethod: 'Cash',
                receiptHref: '#',
            },
            {
                id: 2,
                amount: 400,
                dueDate: 'Dec 1, 2024',
                status: 'Upcoming',
            },
        ],
        sessions: [
            {
                id: 1,
                date: 'Nov 19, 2024',
                day: 'Tue',
                time: '6:00 PM – 8:00 PM',
                room: 'Room 108',
                status: 'Upcoming',
            },
            {
                id: 2,
                date: 'Nov 21, 2024',
                day: 'Thu',
                time: '6:00 PM – 8:00 PM',
                room: 'Room 108',
                status: 'Upcoming',
            },
            {
                id: 3,
                date: 'Nov 14, 2024',
                day: 'Tue',
                time: '6:00 PM – 8:00 PM',
                room: 'Room 108',
                status: 'Completed',
            },
            {
                id: 4,
                date: 'Nov 7, 2024',
                day: 'Thu',
                time: '6:00 PM – 8:00 PM',
                room: 'Room 108',
                status: 'Completed',
            },
        ],
    },
};

function readCourseId(url: string) {
    const path = url.split('?')[0];
    const match = path.match(/\/courses\/(\d+)$/);
    return Number(match?.[1] ?? 1);
}

function readInitialTab(url: string): CourseDetailsTab {
    const query = url.includes('?') ? url.split('?')[1] : '';
    const params = new URLSearchParams(query);
    const requested = params.get('tab');

    if (
        requested === 'schedule' ||
        requested === 'attendance' ||
        requested === 'payments'
    ) {
        return requested;
    }

    return 'overview';
}
type CourseDetailsPageProps = PageProps & {
    courseDetails?: StudentCourseDetailsPageData;
};

export default function CourseDetails() {
    const page =
        usePage<CourseDetailsPageProps>();

    const demoMode =
        page.url.startsWith('/demo');

    const backHref =
        demoMode
            ? '/demo/courses'
            : '/my-courses';

    const courseId =
        useMemo(
            () =>
                readCourseId(
                    page.url
                ),
            [page.url],
        );

    const demoCourse =
        courses[courseId]
        ?? courses[1];

    const pageData:
        StudentCourseDetailsPageData =
        demoMode
            ? {
                  student: {
                      name:
                          'Mohammad Znaid',
                      studentId:
                          'STU-2024-0842',
                  },

                  center: {
                      name:
                          'Al-Hilal Language Center',
                      branch:
                          'Gaza – Palestine',
                  },

                  course:
                      demoCourse,
              }
            : page.props
                  .courseDetails!;

    const course =
        pageData.course;

    const [
        activeTab,
        setActiveTab,
    ] =
        useState<CourseDetailsTab>(
            () =>
                readInitialTab(
                    page.url
                )
        );

    const changeTab = (
        tab: CourseDetailsTab
    ) => {
        setActiveTab(tab);

        const base =
            page.url.split('?')[0];

        const nextUrl =
            tab === 'overview'
                ? base
                : `${base}?tab=${tab}`;

        window.history.replaceState(
            {},
            '',
            nextUrl
        );
    };

    return (
        <StudentLayout
            studentName={
                pageData.student.name
            }
            studentId={
                pageData.student
                    .studentId
            }
            centerName={
                pageData.center.name
            }
            branchName={
                pageData.center.branch
            }
            pageTitle="Course Details"
            activeNav="courses"
            mobileBackHref={
                backHref
            }
            fluid
        >
            <Head
                title={`${course.title} - Course Details`}
            />

            <div className="space-y-5">
                <CourseDetailsHeader
                    title={
                        course.title
                    }
                    status={
                        course.status
                    }
                    backHref={
                        backHref
                    }
                />

                <CourseTabs
                    activeTab={
                        activeTab
                    }
                    onTabChange={
                        changeTab
                    }
                />

                {activeTab ===
                    'overview' && (
                    <CourseOverview
                        course={
                            course
                        }
                    />
                )}

                {activeTab ===
                    'schedule' && (
                    <ScheduleTab
                        sessions={
                            course.sessions
                        }
                    />
                )}

                {activeTab ===
                    'attendance' && (
                    <AttendanceTab
                        summary={
                            course.attendance
                        }
                        log={
                            course.attendanceLog
                        }
                    />
                )}

                {activeTab ===
                    'payments' && (
                    <PaymentsTab
                        summary={
                            course.payment
                        }
                        installments={
                            course.installments
                        }
                    />
                )}
            </div>
        </StudentLayout>
    );
}