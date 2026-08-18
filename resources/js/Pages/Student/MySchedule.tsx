import '../../../css/student-schedule.css';

import ScheduleCalendar from '@/Features/Student/Schedule/ScheduleCalendar';
import ScheduleSessionModal from '@/Features/Student/Schedule/ScheduleSessionModal';
import SelectedDayPanel from '@/Features/Student/Schedule/SelectedDayPanel';
import StudentLayout from '@/Layouts/StudentLayout';
import type {
    ScheduleCourseOption,
    ScheduleSession,
} from '@/types/student-schedule';
import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const DEMO_TODAY = '2024-11-18';

const courseOptions: ScheduleCourseOption[] = [
    { id: 1, label: 'English – Intermediate' },
    { id: 2, label: 'French – Beginner' },
];

const demoSessions: ScheduleSession[] = [
    {
        id: 1,
        courseId: 2,
        courseName: 'French – Beginner',
        teacher: 'Ms. Leila Mansouri',
        room: 'Room 108',
        date: '2024-11-07',
        time: '6:00 PM – 8:00 PM',
        calendarLabel: 'French 6:00 PM',
        status: 'Completed',
    },
    {
        id: 2,
        courseId: 1,
        courseName: 'English – Intermediate',
        teacher: 'Mr. Hussam Al-Attar',
        room: 'Room 204',
        date: '2024-11-11',
        time: '5:00 PM – 7:00 PM',
        calendarLabel: 'English 5:00 PM',
        status: 'Cancelled',
    },
    {
        id: 3,
        courseId: 1,
        courseName: 'English – Intermediate',
        teacher: 'Mr. Hussam Al-Attar',
        room: 'Room 204',
        date: '2024-11-13',
        time: '5:00 PM – 7:00 PM',
        calendarLabel: 'English 5:00 PM',
        status: 'Completed',
    },
    {
        id: 4,
        courseId: 2,
        courseName: 'French – Beginner',
        teacher: 'Ms. Leila Mansouri',
        room: 'Room 108',
        date: '2024-11-14',
        time: '6:00 PM – 8:00 PM',
        calendarLabel: 'French 6:00 PM',
        status: 'Completed',
    },
    {
        id: 5,
        courseId: 1,
        courseName: 'English – Intermediate',
        teacher: 'Mr. Hussam Al-Attar',
        room: 'Room 204',
        date: '2024-11-18',
        time: '5:00 PM – 7:00 PM',
        calendarLabel: 'English 5:00 PM',
        status: 'Upcoming',
    },
    {
        id: 6,
        courseId: 2,
        courseName: 'French – Beginner',
        teacher: 'Ms. Leila Mansouri',
        room: 'Room 108',
        date: '2024-11-19',
        time: '6:00 PM – 8:00 PM',
        calendarLabel: 'French 6:00 PM',
        status: 'Upcoming',
    },
    {
        id: 7,
        courseId: 1,
        courseName: 'English – Intermediate',
        teacher: 'Mr. Hussam Al-Attar',
        room: 'Room 204',
        date: '2024-11-20',
        time: '5:00 PM – 7:00 PM',
        calendarLabel: 'English 5:00 PM',
        status: 'Upcoming',
    },
    {
        id: 8,
        courseId: 2,
        courseName: 'French – Beginner',
        teacher: 'Ms. Leila Mansouri',
        room: 'Room 108',
        date: '2024-11-21',
        time: '6:00 PM – 8:00 PM',
        calendarLabel: 'French 6:00 PM',
        status: 'Upcoming',
    },
];

type OptionalAuthProps = {
    auth?: {
        user?: {
            name?: string;
        } | null;
    };
};

export default function MySchedule() {
    const page = usePage();
    const props = page.props as OptionalAuthProps;

    const studentName =
        props.auth?.user?.name || 'Mohammad Znaid';

    const [month, setMonth] = useState(
        () => new Date(2024, 10, 1),
    );

    const [selectedDate, setSelectedDate] =
        useState(DEMO_TODAY);

    const [courseId, setCourseId] = useState('all');

    const [selectedSession, setSelectedSession] =
        useState<ScheduleSession | null>(null);

    const filteredCalendarSessions = useMemo(() => {
        if (courseId === 'all') {
            return demoSessions;
        }

        return demoSessions.filter(
            (session) =>
                session.courseId.toString() === courseId,
        );
    }, [courseId]);

    const moveMonth = (amount: number) => {
        setMonth(
            (current) =>
                new Date(
                    current.getFullYear(),
                    current.getMonth() + amount,
                    1,
                ),
        );
    };

    return (
        <StudentLayout
            studentName={studentName}
            studentId="STU-2024-0842"
            centerName="Al-Hilal Language Center"
            branchName="Riyadh – Main Branch"
            pageTitle="My Schedule"
            activeNav="schedule"
            fluid
        >
            <Head title="My Schedule" />

            <div className="schedule-page">
                <div className="schedule-shell">
                    <div className="schedule-selected-pane">
                        <SelectedDayPanel
                            selectedDate={selectedDate}
                            demoToday={DEMO_TODAY}
                            courseId={courseId}
                            courseOptions={courseOptions}
                            sessions={demoSessions}
                            onCourseChange={setCourseId}
                            onOpenSession={setSelectedSession}
                        />
                    </div>

                    <div className="schedule-calendar-pane">
                        <ScheduleCalendar
                            month={month}
                            selectedDate={selectedDate}
                            sessions={filteredCalendarSessions}
                            onPreviousMonth={() => moveMonth(-1)}
                            onNextMonth={() => moveMonth(1)}
                            onSelectDate={setSelectedDate}
                            onOpenSession={setSelectedSession}
                        />
                    </div>
                </div>

                <ScheduleSessionModal
                    session={selectedSession}
                    onClose={() => setSelectedSession(null)}
                />
            </div>
        </StudentLayout>
    );
}
