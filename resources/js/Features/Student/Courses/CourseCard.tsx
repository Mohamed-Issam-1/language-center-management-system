import { Link } from '@inertiajs/react';
import type { StudentCourse } from '@/types/student-courses';

const accentClasses = {
    indigo: 'bg-[#3849cf]',
    violet: 'bg-[#7b35f1]',
};

export default function CourseCard({ course }: { course: StudentCourse }) {
    return (
        <article className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <div className="flex items-start gap-3">
                <div
                    className={`grid h-12 w-12 shrink-0 place-items-center rounded-[11px] text-[13px] font-extrabold text-white ${accentClasses[course.accent]}`}
                >
                    {course.code}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="truncate text-[15px] font-extrabold text-[#262b33]">
                                {course.title}
                            </h2>
                            <p className="mt-1 truncate text-[11px] text-[#aeb7c4]">
                                {course.levelName} · {course.classCode}
                            </p>
                        </div>

                        <span className="shrink-0 rounded-full bg-[#ddf7fb] px-3 py-1 text-[10px] font-bold text-[#04a9bf]">
                            {course.status}
                        </span>
                    </div>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-x-5 gap-y-3">
                <div>
                    <p className="text-[9px] font-bold uppercase tracking-wide text-[#c1c8d2]">
                        Teacher
                    </p>
                    <p className="mt-1 text-[11px] font-bold text-[#3f454e]">
                        {course.teacher}
                    </p>
                </div>

                <div>
                    <p className="text-[9px] font-bold uppercase tracking-wide text-[#c1c8d2]">
                        Branch
                    </p>
                    <p className="mt-1 text-[11px] font-bold text-[#3f454e]">
                        {course.branch}
                    </p>
                </div>

                <div className="col-span-2">
                    <p className="text-[9px] font-bold uppercase tracking-wide text-[#c1c8d2]">
                        Schedule
                    </p>
                    <p className="mt-1 text-[11px] font-bold text-[#3f454e]">
                        {course.schedule}
                    </p>
                </div>

                <div className="col-span-2">
                    <p className="text-[9px] font-bold uppercase tracking-wide text-[#c1c8d2]">
                        Duration
                    </p>
                    <p className="mt-1 text-[11px] font-bold text-[#3f454e]">
                        {course.duration}
                    </p>
                </div>
            </div>

            <div className="mt-4">
                <div className="mb-1.5 flex items-center justify-between text-[10px]">
                    <span className="text-[#b2bac6]">Attendance</span>
                    <span className="font-extrabold text-[#04a9bf]">
                        {course.attendance}%
                    </span>
                </div>
                <div className="h-1 rounded-full bg-[#edf0f4]">
                    <div
                        className="h-1 rounded-full bg-[#04a9bf]"
                        style={{ width: `${course.attendance}%` }}
                    />
                </div>
            </div>

            {course.detailsHref ? (
                <Link
                    href={course.detailsHref}
                    className="mt-4 flex h-[41px] w-full items-center justify-center rounded-[9px] bg-[#052f98] text-[12px] font-extrabold text-white transition hover:bg-[#04277f]"
                >
                    View Details →
                </Link>
            ) : (
                <button
                    type="button"
                    className="mt-4 h-[41px] w-full rounded-[9px] bg-[#052f98] text-[12px] font-extrabold text-white transition hover:bg-[#04277f]"
                >
                    View Details →
                </button>
            )}
        </article>
    );
}
