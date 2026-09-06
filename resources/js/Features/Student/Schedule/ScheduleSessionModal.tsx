import Modal from '@/Components/Modal';
import {
    CalendarDays,
    Clock3,
    DoorOpen,
    UserRound,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ScheduleSession } from '@/types/student-schedule';

function prettyDate(dateKey: string) {
    const date = new Date(`${dateKey}T12:00:00`);

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        weekday: 'long',
    });
}

function DetailRow({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div className="schedule-modal-row">
            <div className="schedule-modal-label">
                <Icon size={15} />
                <span>{label}</span>
            </div>

            <div className="schedule-modal-value">{value}</div>
        </div>
    );
}

export default function ScheduleSessionModal({
    session,
    onClose,
}: {
    session: ScheduleSession | null;
    onClose: () => void;
}) {
    return (
        <Modal show={session !== null} onClose={onClose} maxWidth="md">
            {session && (
                <div className="schedule-modal">
                    <button
                        type="button"
                        className="schedule-modal-close"
                        onClick={onClose}
                        aria-label="Close session details"
                    >
                        <X size={16} />
                    </button>

                    <span
                        className={`schedule-status ${session.status.toLowerCase()}`}
                    >
                        {session.status}
                    </span>

                    <h2 className="schedule-modal-title">
                        {session.courseName}
                    </h2>

                    <div className="schedule-modal-details">
                        <DetailRow
                            icon={CalendarDays}
                            label="Date"
                            value={prettyDate(session.date)}
                        />

                        <DetailRow
                            icon={Clock3}
                            label="Time"
                            value={session.time}
                        />

                        <DetailRow
                            icon={UserRound}
                            label="Teacher"
                            value={session.teacher}
                        />

                        <DetailRow
                            icon={DoorOpen}
                            label="Room"
                            value={session.room}
                        />
                    </div>
                </div>
            )}
        </Modal>
    );
}
