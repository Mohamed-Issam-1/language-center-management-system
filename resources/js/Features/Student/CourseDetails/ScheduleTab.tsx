import SessionSection from './SessionSection';
import type { CourseSession } from '@/types/student-course-details';

export default function ScheduleTab({
    sessions,
}: {
    sessions: CourseSession[];
}) {
    return (
        <div className="space-y-4 lg:space-y-5">
            <SessionSection
                title="Upcoming Sessions"
                status="Upcoming"
                sessions={sessions}
            />
            <SessionSection
                title="Completed Sessions"
                status="Completed"
                sessions={sessions}
            />
        </div>
    );
}
