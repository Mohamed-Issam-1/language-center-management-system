import type { AttendanceCourseSummary } from '@/types/student-attendance';

export default function AttendanceByCourse({
    courses,
}: {
    courses: AttendanceCourseSummary[];
}) {
    return (
        <section className="records-card records-card-pad">
            <h2 className="records-heading">
                Attendance by Course
            </h2>

            <div className="attendance-course-list">
                {courses.map((course) => (
                    <article
                        key={course.id}
                        className="attendance-course-row"
                    >
                        <div className="attendance-course-top">
                            <h3 className="attendance-course-name">
                                {course.courseName}
                            </h3>
                            <span className="attendance-course-rate">
                                {course.rate}%
                            </span>
                        </div>

                        <div className="attendance-progress-track">
                            <div
                                className="attendance-progress-value"
                                style={{ width: `${course.rate}%` }}
                            />
                        </div>

                        <div className="attendance-course-breakdown">
                            <span className="present">
                                ✓ {course.present} present
                            </span>
                            <span className="absent">
                                × {course.absent} absent
                            </span>
                            {course.late > 0 && (
                                <span className="late">
                                    ⏱ {course.late} late
                                </span>
                            )}
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
