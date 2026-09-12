import type {
    StudentNotification,
} from '@/types/student-notifications';
import {
    BarChart3,
    Bell,
    ClipboardList,
    CreditCard,
} from 'lucide-react';

const icons = {
    grade: BarChart3,
    attendance:
        ClipboardList,
    payment: CreditCard,
    general: Bell,
};

export default function NotificationCard({
    notification,
    unread,
    onRead,
}: {
    notification: StudentNotification;
    unread: boolean;
    onRead: () => void;
}) {
    const Icon =
        icons[
            notification.type
        ];

    return (
        <button
            type="button"
            className={[
                'student-notification-card',
                unread
                    ? 'unread'
                    : '',
            ]
                .filter(Boolean)
                .join(' ')}
            onClick={onRead}
        >
            <div
                className={`student-notification-icon ${notification.type}`}
            >
                <Icon
                    size={19}
                    strokeWidth={1.8}
                />
            </div>

            <div className="student-notification-content">
                <h2>
                    {
                        notification.title
                    }
                </h2>

                <p>
                    {
                        notification.message
                    }
                </p>

                <time>
                    {
                        notification.date
                    }
                </time>
            </div>

            {unread && (
                <span className="student-notification-unread-dot" />
            )}
        </button>
    );
}