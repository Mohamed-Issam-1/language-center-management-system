import ScheduleSessionCard from './ScheduleSessionCard';
import type {
    ScheduleCourseOption,
    ScheduleSession,
} from '@/types/student-schedule';

function formatSelectedDate(
    dateKey: string,
) {
    const date =
        new Date(
            `${dateKey}T12:00:00`,
        );

    return {
        heading:
            date.toLocaleDateString(
                'en-US',
                {
                    weekday: 'long',
                    day: 'numeric',
                },
            ),

        monthYear:
            date.toLocaleDateString(
                'en-US',
                {
                    month: 'long',
                    year: 'numeric',
                },
            ),
    };
}

export default function SelectedDayPanel({
    selectedDate,
    today,
    courseId,
    courseOptions,
    sessions,
    onCourseChange,
    onOpenSession,
}: {
    selectedDate: string;
    today: string;
    courseId: string;
    courseOptions: ScheduleCourseOption[];
    sessions: ScheduleSession[];
    onCourseChange: (
        courseId: string,
    ) => void;
    onOpenSession: (
        session: ScheduleSession,
    ) => void;
}) {
    const formatted =
        formatSelectedDate(
            selectedDate,
        );

    const daySessions =
        sessions.filter(
            (session) =>
                session.date ===
                    selectedDate &&
                (courseId === 'all' ||
                    session.courseId.toString() ===
                        courseId),
        );

    return (
        <section className="schedule-selected-card">
            <div className="schedule-selected-header">
                <p className="schedule-kicker">
                    Selected Day
                </p>

                <h2 className="schedule-selected-title">
                    {
                        formatted.heading
                    }
                </h2>

                <p className="schedule-selected-subtitle">
                    {
                        formatted.monthYear
                    }

                    {selectedDate === today
                        ? ' · Today'
                        : ''}
                </p>
            </div>

            <div className="schedule-filter-wrap">
                <select
                    value={
                        courseId
                    }
                    onChange={(
                        event,
                    ) =>
                        onCourseChange(
                            event.target
                                .value,
                        )
                    }
                    className="schedule-filter"
                    aria-label="Filter schedule by course"
                >
                    <option value="all">
                        All Courses
                    </option>

                    {courseOptions.map(
                        (course) => (
                            <option
                                key={
                                    course.id
                                }
                                value={course.id.toString()}
                            >
                                {
                                    course.label
                                }
                            </option>
                        ),
                    )}
                </select>
            </div>

            <div className="schedule-selected-body">
                {daySessions.length >
                0 ? (
                    daySessions.map(
                        (session) => (
                            <ScheduleSessionCard
                                key={
                                    session.id
                                }
                                session={
                                    session
                                }
                                onOpen={
                                    onOpenSession
                                }
                            />
                        ),
                    )
                ) : (
                    <div className="schedule-empty">
                        <p className="schedule-empty-title">
                            No sessions
                        </p>

                        <p className="schedule-empty-copy">
                            There are no
                            sessions for the
                            selected day.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}