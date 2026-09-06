import { DoorOpen, UserRound, CalendarDays } from 'lucide-react';
import type { DashboardSession } from '@/types/student-dashboard';

export default function NextSessionCard({
    session,
}: {
    session: DashboardSession | null;
}) {
    if (!session) {
        return null;
    }

    return (
        <section className="rounded-[16px] border-2 border-[#4959ef] border-l-[4px] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.04)]">
            <p className="text-[10px] font-extrabold uppercase tracking-wide text-[#b0b8c4]">
                Next Session
            </p>

            <div className="mt-4 flex items-start justify-between gap-3">
                <div>
                    <h3 className="text-[14px] font-extrabold text-[#242a32]">
                        {session.course}
                    </h3>
                    <div className="mt-2 space-y-1 text-[11px] text-[#8f99a9]">
                        <p className="flex items-center gap-2">
                            <UserRound size={12} />
                            {session.teacher}
                        </p>
                        <p className="flex items-center gap-2">
                            <DoorOpen size={12} />
                            {session.room}
                        </p>
                        <p className="flex items-center gap-2">
                            <CalendarDays size={12} />
                            {session.time}
                        </p>
                    </div>
                </div>

                <span className="rounded-full bg-[#e8edf8] px-3 py-1 text-[10px] font-bold text-[#0f378c]">
                    Upcoming
                </span>
            </div>
        </section>
    );
}
