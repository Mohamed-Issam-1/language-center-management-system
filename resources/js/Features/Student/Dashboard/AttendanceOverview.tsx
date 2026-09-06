import type { DashboardCourse } from '@/types/student-dashboard';

function CourseAttendance({ course }: { course: DashboardCourse }) {
    return (
        <div>
            <div className="flex items-end justify-between">
                <div>
                    <p className="text-[11px] font-bold text-[#4f5662]">
                        {course.title}
                    </p>
                    <p className="mt-1 text-[10px] text-[#aeb6c2]">Attendance</p>
                </div>
                <span className="text-[10px] font-extrabold text-[#04a9bf]">
                    {course.attendance}%
                </span>
            </div>
            <div className="mt-1.5 h-1 rounded-full bg-[#edf0f4]">
                <div
                    className="h-1 rounded-full bg-[#04a9bf]"
                    style={{ width: `${course.attendance}%` }}
                />
            </div>
        </div>
    );
}

export default function AttendanceOverview({
    average,
    totalAbsent,
    courses,
}: {
    average: number;
    totalAbsent: number;
    courses: DashboardCourse[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Attendance Overview
            </h3>

            <div className="mt-5 grid grid-cols-2 gap-3">
                <div className="rounded-[12px] bg-[#f2f5fb] py-4 text-center">
                    <p className="text-[22px] font-extrabold text-[#04a9bf]">
                        {average}%
                    </p>
                    <p className="mt-1 text-[9px] text-[#b2bac6]">
                        Avg Attendance
                    </p>
                </div>
                <div className="rounded-[12px] bg-[#f2f5fb] py-4 text-center">
                    <p className="text-[22px] font-extrabold text-[#e62d36]">
                        {totalAbsent}
                    </p>
                    <p className="mt-1 text-[9px] text-[#b2bac6]">
                        Total Absent
                    </p>
                </div>
            </div>

            <div className="mt-5 space-y-4">
                {courses.map((course) => (
                    <CourseAttendance
                        key={`${course.code}-${course.title}`}
                        course={course}
                    />
                ))}
            </div>
        </section>
    );
}
