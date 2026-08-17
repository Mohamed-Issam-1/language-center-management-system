import CourseCard from './CourseCard';
import type { StudentCourse } from '@/types/student-courses';

export default function CourseGrid({
    courses,
}: {
    courses: StudentCourse[];
}) {
    if (courses.length === 0) {
        return (
            <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-6 py-16 text-center shadow-[0_2px_8px_rgba(16,24,40,0.05)]">
                <h2 className="text-[15px] font-extrabold text-[#263142]">
                    No courses found
                </h2>
                <p className="mt-2 text-[12px] text-[#98a3b2]">
                    Try changing the search term or filters.
                </p>
            </section>
        );
    }

    return (
        <section className="grid gap-4 md:grid-cols-2 lg:gap-5">
            {courses.map((course) => (
                <CourseCard key={course.id} course={course} />
            ))}
        </section>
    );
}
