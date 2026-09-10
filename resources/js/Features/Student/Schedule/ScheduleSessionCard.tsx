import {
    Clock3,
    DoorOpen,
    UserRound,
} from 'lucide-react';
import type { ScheduleSession } from '@/types/student-schedule';

export default function ScheduleSessionCard({
    session,
    onOpen,
}: {
    session: ScheduleSession;

    onOpen: (
        session: ScheduleSession,
    ) => void;
}) {
    const statusClass =
        session.status.toLowerCase();

    return (
        <button
            type="button"
            className={`schedule-session-card ${statusClass}`}
            onClick={() =>
                onOpen(session)
            }
        >
            <div className="schedule-session-topline">
                <span className="schedule-session-type">
                    <span className="schedule-session-type-dot" />

                    Session
                </span>

                <span
                    className={`schedule-status ${statusClass}`}
                >
                    {
                        session.status
                    }
                </span>
            </div>

            <h3 className="schedule-session-name">
                {
                    session.courseName
                }
            </h3>

            <div className="schedule-session-meta">
                <p className="schedule-session-meta-row">
                    <Clock3
                        size={14}
                    />

                    {
                        session.time
                    }
                </p>

                <p className="schedule-session-meta-row">
                    <UserRound
                        size={14}
                    />

                    {
                        session.teacher
                    }
                </p>

                <p className="schedule-session-meta-row">
                    <DoorOpen
                        size={14}
                    />

                    {
                        session.room
                    }
                </p>
            </div>
        </button>
    );
}