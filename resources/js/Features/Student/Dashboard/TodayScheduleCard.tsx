import { CalendarDays } from 'lucide-react';
import type { DashboardSession } from '@/types/student-dashboard';

export default function TodayScheduleCard({
    sessions,
}: {
    sessions: DashboardSession[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Today's Schedule
            </h3>
            <p className="mt-1 text-[10px] text-[#b1bac7]">Mon, Nov 18</p>

            <div className="mt-5 space-y-3">
                {sessions.map((session) => (
                    <div
                        key={`${session.course}-${session.time}`}
                        className="flex items-center gap-4"
                    >
                        <div className="grid h-10 w-10 shrink-0 place-items-center rounded-[10px] bg-[#e9eef9] text-[#153c91]">
                            <CalendarDays size={18} />
                        </div>

                        <div className="min-w-0 flex-1">
                            <h4 className="text-[14px] font-extrabold text-[#242a32]">
                                {session.course}
                            </h4>
                            <p className="mt-1 text-[11px] text-[#b0b8c5]">
                                {session.time.replace('Today · ', '')} · {session.room}
                            </p>
                        </div>

                        <span className="rounded-full bg-[#e8edf8] px-3 py-1 text-[10px] font-bold text-[#0f378c]">
                            Upcoming
                        </span>
                    </div>
                ))}
            </div>
        </section>
    );
}
