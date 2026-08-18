import AttendanceLogRow from './AttendanceLogRow';
import type { AttendanceLogEntry } from '@/types/student-course-details';

export default function AttendanceLog({
    entries,
}: {
    entries: AttendanceLogEntry[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)] sm:px-6">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Attendance Log
            </h3>

            <div className="mt-4">
                {entries.map((entry) => (
                    <AttendanceLogRow key={entry.id} entry={entry} />
                ))}
            </div>
        </section>
    );
}
