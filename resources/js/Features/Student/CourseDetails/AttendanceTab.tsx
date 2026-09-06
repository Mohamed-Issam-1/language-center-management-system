import AttendanceLog from './AttendanceLog';
import AttendanceStats from './AttendanceStats';
import type {
    AttendanceLogEntry,
    QuickAttendanceData,
} from '@/types/student-course-details';

export default function AttendanceTab({
    summary,
    log,
}: {
    summary: QuickAttendanceData;
    log: AttendanceLogEntry[];
}) {
    return (
        <div className="space-y-4 lg:space-y-5">
            <AttendanceStats data={summary} />
            <AttendanceLog entries={log} />
        </div>
    );
}
