import type { AttendanceAlertData } from '@/types/student-attendance';
import { TriangleAlert } from 'lucide-react';

export default function AttendanceAlert({
    alert,
}: {
    alert: AttendanceAlertData | null;
}) {
    if (!alert) {
        return null;
    }

    return (
        <section className="records-alert danger">
            <TriangleAlert
                size={20}
                className="records-alert-icon"
            />

            <div>
                <h2 className="records-alert-title">
                    {alert.title}
                </h2>

                <p className="records-alert-copy">
                    {alert.message}
                </p>
            </div>
        </section>
    );
}