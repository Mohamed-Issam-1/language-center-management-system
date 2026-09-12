import type { CourseSession } from '@/types/student-course-details';

const statusClasses = {
    Upcoming:
        'bg-[#e8edf8] text-[#07358a]',
    Completed:
        'bg-[#e9edf0] text-[#6f7881]',
    Cancelled:
        'bg-[#ffe0e0] text-[#e52b34]',
};

const accentClasses = {
    Upcoming:
        'border-l-[#083b9d]',
    Completed:
        'border-l-[#08b3ca]',
    Cancelled:
        'border-l-[#ef3139]',
};

export default function SessionRow({ session }: { session: CourseSession }) {
    return (
        <article
            className={[
                'flex items-center justify-between gap-4 rounded-[11px] border-l-[4px] bg-[#f4f6fb] px-4 py-3.5',
                accentClasses[session.status],
            ].join(' ')}
        >
            <div className="min-w-0">
                <p className="text-[12px] font-extrabold text-[#30353e]">
                    {session.date} · {session.day}
                </p>
                <p className="mt-1 text-[11px] text-[#aeb7c4]">
                    {session.time} · {session.room}
                </p>
            </div>

            <span
                className={[
                    'shrink-0 rounded-full px-3 py-1 text-[9px] font-bold',
                    statusClasses[session.status],
                ].join(' ')}
            >
                {session.status}
            </span>
        </article>
    );
}
