import type { DashboardCourse } from '@/types/student-dashboard';

function CourseRow({ course }: { course: DashboardCourse }) {
    const badge =
        course.accent === 'indigo'
            ? 'bg-[#3849cf]'
            : 'bg-[#7936ef]';

    return (
        <div className="flex gap-4 py-5 first:pt-3 last:pb-2">
            <div
                className={`mt-2 grid h-10 w-10 shrink-0 place-items-center rounded-[11px] text-[13px] font-extrabold text-white ${badge}`}
            >
                {course.code}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h4 className="text-[14px] font-extrabold text-[#242a32]">
                            {course.title}
                        </h4>
                        <p className="mt-1 text-[11px] leading-4 text-[#a7b0be]">
                            {course.teacher} · {course.schedule}
                        </p>
                    </div>

                    <span className="shrink-0 rounded-full bg-[#ddf7fb] px-3 py-1 text-[10px] font-bold text-[#05a9bd]">
                        Active
                    </span>
                </div>

                <div className="mt-3">
                    <div className="mb-1.5 flex items-center justify-between text-[10px]">
                        <span className="text-[#b2bac6]">Attendance</span>
                        <span className="font-bold text-[#05a9bd]">
                            {course.attendance}%
                        </span>
                    </div>
                    <div className="h-1 rounded-full bg-[#edf0f4]">
                        <div
                            className="h-1 rounded-full bg-[#05abc0]"
                            style={{ width: `${course.attendance}%` }}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function ActiveCoursesCard({
    courses,
}: {
    courses: DashboardCourse[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Active Courses
            </h3>

            <div className="mt-3 divide-y divide-[#edf0f4]">
                {courses.map((course) => (
                    <CourseRow key={`${course.code}-${course.title}`} course={course} />
                ))}
            </div>
        </section>
    );
}
