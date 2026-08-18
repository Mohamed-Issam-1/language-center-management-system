import { CalendarDays } from 'lucide-react';
import type { AttendanceLogEntry } from '@/types/student-course-details';

const statusClasses = {
    Present: 'bg-[#ddf7fb] text-[#03a7bd]',
    Absent: 'bg-[#ffe0e0] text-[#e52b34]',
    Late: 'bg-[#fff8e5] text-[#ef9900]',
};

export default function AttendanceLogRow({
    entry,
}: {
    entry: AttendanceLogEntry;
}) {
    return (
        <article className="flex items-center gap-4 border-b border-[#edf0f4] py-4 last:border-b-0">
            <div className="grid h-10 w-10 shrink-0 place-items-center rounded-[10px] bg-[#e8edf8] text-[#073997]">
                <CalendarDays size={18} strokeWidth={1.8} />
            </div>

            <div className="min-w-0 flex-1">
                <p className="text-[12px] font-extrabold text-[#30353e]">
                    {entry.date}
                </p>
                {entry.note && (
                    <p className="mt-1 text-[10px] text-[#aeb7c4]">
                        {entry.note}
                    </p>
                )}
            </div>

            <span
                className={[
                    'shrink-0 rounded-full px-3 py-1 text-[9px] font-bold',
                    statusClasses[entry.status],
                ].join(' ')}
            >
                {entry.status}
            </span>
        </article>
    );
}
