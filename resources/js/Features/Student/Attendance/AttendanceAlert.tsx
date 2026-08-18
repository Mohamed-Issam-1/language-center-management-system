import { TriangleAlert } from 'lucide-react';
import type { AttendanceSummary } from '@/types/student-attendance';

export default function AttendanceAlert({
    summary,
}: {
    summary: AttendanceSummary;
}) {
    const remaining = Math.max(
        0,
        summary.maximumAbsences - summary.absent,
    );

    return (
        <section className="records-alert danger">
            <TriangleAlert
                size={20}
                className="records-alert-icon"
            />

            <div>
                <h2 className="records-alert-title">
                    Approaching Absence Limit
                </h2>
                <p className="records-alert-copy">
                    You have {summary.absent} absences. The maximum
                    allowed is {summary.maximumAbsences}. {remaining}{' '}
                    absences remaining before limit is reached.
                </p>
            </div>
        </section>
    );
}
