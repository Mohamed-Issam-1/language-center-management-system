import { ChevronLeft, ChevronRight } from 'lucide-react';
import type {
    ScheduleSession,
    ScheduleSessionStatus,
} from '@/types/student-schedule';

const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function pad(value: number) {
    return value.toString().padStart(2, '0');
}

function toDateKey(date: Date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(
        date.getDate(),
    )}`;
}

function buildCalendarDays(month: Date) {
    const year = month.getFullYear();
    const monthIndex = month.getMonth();
    const first = new Date(year, monthIndex, 1);
    const gridStart = new Date(year, monthIndex, 1 - first.getDay());

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(gridStart);
        date.setDate(gridStart.getDate() + index);

        return {
            date,
            key: toDateKey(date),
            currentMonth: date.getMonth() === monthIndex,
        };
    });
}

function statusClass(status: ScheduleSessionStatus) {
    return status.toLowerCase();
}

export default function ScheduleCalendar({
    month,
    selectedDate,
    sessions,
    onPreviousMonth,
    onNextMonth,
    onSelectDate,
    onOpenSession,
}: {
    month: Date;
    selectedDate: string;
    sessions: ScheduleSession[];
    onPreviousMonth: () => void;
    onNextMonth: () => void;
    onSelectDate: (date: string) => void;
    onOpenSession: (session: ScheduleSession) => void;
}) {
    const days = buildCalendarDays(month);

    const monthTitle = month.toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    });

    return (
        <section className="schedule-calendar-card">
            <div className="schedule-calendar-header">
                <button
                    type="button"
                    className="schedule-month-button"
                    onClick={onPreviousMonth}
                    aria-label="Previous month"
                >
                    <ChevronLeft size={17} />
                </button>

                <h2 className="schedule-calendar-title">{monthTitle}</h2>

                <button
                    type="button"
                    className="schedule-month-button"
                    onClick={onNextMonth}
                    aria-label="Next month"
                >
                    <ChevronRight size={17} />
                </button>
            </div>

            <div className="schedule-weekdays">
                {weekDays.map((day) => (
                    <div key={day} className="schedule-weekday">
                        {day}
                    </div>
                ))}
            </div>

            <div className="schedule-calendar-grid">
                {days.map(({ date, key, currentMonth }) => {
                    const daySessions = sessions.filter(
                        (session) => session.date === key,
                    );

                    const selected = key === selectedDate;

                    return (
                        <button
                            key={key}
                            type="button"
                            className={[
                                'schedule-day',
                                !currentMonth ? 'is-muted' : '',
                                selected ? 'is-selected' : '',
                            ]
                                .filter(Boolean)
                                .join(' ')}
                            onClick={() => onSelectDate(key)}
                        >
                            <span className="schedule-day-number">
                                {date.getDate()}
                            </span>

                            <span className="schedule-event-list">
                                {daySessions.slice(0, 2).map((session) => (
                                    <span
                                        key={session.id}
                                        role="button"
                                        tabIndex={0}
                                        className={`schedule-event-pill ${statusClass(
                                            session.status,
                                        )}`}
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onSelectDate(key);
                                            onOpenSession(session);
                                        }}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'Enter' ||
                                                event.key === ' '
                                            ) {
                                                event.preventDefault();
                                                event.stopPropagation();
                                                onSelectDate(key);
                                                onOpenSession(session);
                                            }
                                        }}
                                    >
                                        {session.calendarLabel}
                                    </span>
                                ))}
                            </span>

                            <span className="schedule-mobile-dots">
                                {daySessions.slice(0, 3).map((session) => (
                                    <span
                                        key={session.id}
                                        className={`schedule-mobile-dot ${statusClass(
                                            session.status,
                                        )}`}
                                    />
                                ))}
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="schedule-legend">
                <span className="schedule-legend-item">
                    <span className="schedule-legend-box upcoming" />
                    Upcoming
                </span>
                <span className="schedule-legend-item">
                    <span className="schedule-legend-box completed" />
                    Completed
                </span>
                <span className="schedule-legend-item">
                    <span className="schedule-legend-box cancelled" />
                    Cancelled
                </span>
            </div>
        </section>
    );
}
