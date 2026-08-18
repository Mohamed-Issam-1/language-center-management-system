import SessionRow from './SessionRow';
import type {
    CourseSession,
    CourseSessionStatus,
} from '@/types/student-course-details';

export default function SessionSection({
    title,
    status,
    sessions,
}: {
    title: string;
    status: CourseSessionStatus;
    sessions: CourseSession[];
}) {
    const filtered = sessions.filter((session) => session.status === status);

    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)] sm:px-6">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                {title}
            </h3>
            <p className="mt-1 text-[10px] text-[#aeb7c4]">
                {filtered.length} {filtered.length === 1 ? 'session' : 'sessions'}
            </p>

            <div className="mt-4 space-y-3">
                {filtered.map((session) => (
                    <SessionRow key={session.id} session={session} />
                ))}
            </div>
        </section>
    );
}
