import type { GradeCourseOption } from '@/types/student-grades';

export default function GradeFilters({
    courses,
    selectedCourse,
    onChange,
}: {
    courses: GradeCourseOption[];
    selectedCourse: string;
    onChange: (courseId: string) => void;
}) {
    return (
        <div className="grade-filters">
            <button
                type="button"
                className={[
                    'grade-filter',
                    selectedCourse === 'all'
                        ? 'active'
                        : '',
                ]
                    .filter(Boolean)
                    .join(' ')}
                onClick={() =>
                    onChange('all')
                }
            >
                All
            </button>

            {courses.map((course) => (
                <button
                    key={course.id}
                    type="button"
                    className={[
                        'grade-filter',
                        selectedCourse ===
                        course.id.toString()
                            ? 'active'
                            : '',
                    ]
                        .filter(Boolean)
                        .join(' ')}
                    onClick={() =>
                        onChange(
                            course.id.toString(),
                        )
                    }
                >
                    {course.name}
                </button>
            ))}
        </div>
    );
}