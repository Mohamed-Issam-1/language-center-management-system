import '../../../css/student-notifications.css';

import NotificationCard from '@/Features/Student/Notifications/NotificationCard';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type { StudentProfileData } from '@/types/student-profile';
import type {
    StudentNotification,
} from '@/types/student-notifications';
import {
    Head,
    usePage,
} from '@inertiajs/react';
import {
    useEffect,
    useMemo,
    useState,
} from 'react';

const STORAGE_KEY =
    'lcms-student-read-notifications';

const notifications: StudentNotification[] =
    [
        {
            id: 1,

            type: 'grade',

            title:
                'Grades Published – English Intermediate',

            message:
                'Your midterm exam grades for English – Intermediate have been published. You scored 84/100 (B+).',

            date:
                'Sep 2, 2026',

            initiallyRead:
                false,
        },
        {
            id: 2,

            type:
                'attendance',

            title:
                'Attendance Alert – English Intermediate',

            message:
                'Your attendance rate in English – Intermediate has dropped to 88%. Please ensure regular attendance to avoid penalties.',

            date:
                'Aug 31, 2026',

            initiallyRead:
                false,
        },
        {
            id: 3,

            type: 'payment',

            title:
                'Installment Overdue – English Intermediate',

            message:
                'Your installment for English – Intermediate is overdue. Please contact the finance office.',

            date:
                'Aug 26, 2026',

            initiallyRead:
                true,
        },
        {
            id: 4,

            type: 'general',

            title:
                'Mid-term Exam Schedule Published',

            message:
                'Dear students, mid-term examination schedules for all active classes have been published. Please check your portal for your exam dates.',

            date:
                'Aug 28, 2026',

            initiallyRead:
                true,
        },
        {
            id: 5,

            type: 'grade',

            title:
                'Quiz Result – French Beginner',

            message:
                'Your Quiz 1 result for French – Beginner has been posted. You scored 20/20 (A+). Excellent work!',

            date:
                'Aug 11, 2026',

            initiallyRead:
                true,
        },
        {
            id: 6,

            type: 'general',

            title:
                'Holiday Notice – National Day',

            message:
                'The center will be closed on National Day. All sessions scheduled that day will be rescheduled.',

            date:
                'Aug 20, 2026',

            initiallyRead:
                true,
        },
    ];

type NotificationsPageProps =
    PageProps & {
        studentProfile?: StudentProfileData;
    };

export default function Notifications() {
    const page =
        usePage<NotificationsPageProps>();

    const profile =
        page.props
            .studentProfile;

    if (!profile) {
        throw new Error(
            'Student profile data was not provided by Laravel.',
        );
    }

    const [
        readIds,
        setReadIds,
    ] =
        useState<number[]>([]);

    useEffect(() => {
        try {
            const stored =
                window.localStorage.getItem(
                    STORAGE_KEY,
                );

            if (!stored) {
                return;
            }

            setReadIds(
                JSON.parse(
                    stored,
                ) as number[],
            );
        } catch {
            setReadIds([]);
        }
    }, []);

    const unreadIds =
        useMemo(
            () =>
                notifications
                    .filter(
                        (
                            notification,
                        ) =>
                            !notification.initiallyRead &&
                            !readIds.includes(
                                notification.id,
                            ),
                    )
                    .map(
                        (
                            notification,
                        ) =>
                            notification.id,
                    ),
            [readIds],
        );

    const persist = (
        ids: number[],
    ) => {
        setReadIds(ids);

        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify(
                    ids,
                ),
            );
        } catch {
            // Reading notifications still works for this session.
        }
    };

    const markRead = (
        id: number,
    ) => {
        if (
            readIds.includes(
                id,
            )
        ) {
            return;
        }

        persist([
            ...readIds,
            id,
        ]);
    };

    const markAllRead =
        () => {
            persist(
                notifications.map(
                    (
                        notification,
                    ) =>
                        notification.id,
                ),
            );
        };

    return (
        <StudentLayout
            studentName={
                profile.fullName
            }
            studentId={
                profile.studentId
            }
            centerName={
                profile.centerName
            }
            branchName={
                profile.branchName
            }
            pageTitle="Notifications"
            activeNav="notifications"
            fluid
        >
            <Head title="Notifications" />

            <div className="student-notifications-page">
                <div className="student-notifications-topbar">
                    <span>
                        {
                            unreadIds.length
                        }{' '}
                        unread
                    </span>

                    <button
                        type="button"
                        onClick={
                            markAllRead
                        }
                        disabled={
                            unreadIds.length ===
                            0
                        }
                    >
                        Mark all as
                        read
                    </button>
                </div>

                <div className="student-notifications-list">
                    {notifications.map(
                        (
                            notification,
                        ) => {
                            const unread =
                                unreadIds.includes(
                                    notification.id,
                                );

                            return (
                                <NotificationCard
                                    key={
                                        notification.id
                                    }
                                    notification={
                                        notification
                                    }
                                    unread={
                                        unread
                                    }
                                    onRead={() =>
                                        markRead(
                                            notification.id,
                                        )
                                    }
                                />
                            );
                        },
                    )}
                </div>
            </div>
        </StudentLayout>
    );
}