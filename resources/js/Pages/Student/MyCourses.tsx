import CourseFilters, {
    type CourseFilterState,
} from '@/Features/Student/Courses/CourseFilters';
import CourseGrid from '@/Features/Student/Courses/CourseGrid';
import StudentLayout from '@/Layouts/StudentLayout';
import type { StudentCourse } from '@/types/student-courses';
import { Head, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const demoCourses: StudentCourse[] = [
    {
        id: 1,
        code: 'B2',
        title: 'English – Intermediate',
        levelName: 'B2 Intermediate',
        classCode: 'ENG-B2-03',
        teacher: 'Mr. Hussam Al-Attar',
        branch: 'Riyadh – Main Branch',
        schedule: 'Mon, Wed · 5:00 – 7:00 PM',
        duration: 'Sep 5, 2024 – Dec 20, 2024',
        attendance: 88,
        status: 'Active',
        accent: 'indigo',
    },
    {
        id: 2,
        code: 'A1',
        title: 'French – Beginner',
        levelName: 'A1 Beginner',
        classCode: 'FRE-A1-01',
        teacher: 'Ms. Leila Mansouri',
        branch: 'Riyadh – Main Branch',
        schedule: 'Tue, Thu · 6:00 – 8:00 PM',
        duration: 'Oct 1, 2024 – Dec 15, 2024',
        attendance: 95,
        status: 'Active',
        accent: 'violet',
    },
];

type OptionalAuthProps = {
    auth?: {
        user?: {
            name?: string;
        } | null;
    };
};

export default function MyCourses() {
    const page = usePage();
    const auth = page.props as OptionalAuthProps;
    const studentName = auth.auth?.user?.name || 'Mohammad Znaid';

    const [filters, setFilters] = useState<CourseFilterState>({
        search: '',
        status: 'All',
        branch: 'All',
    });

    const statuses = useMemo(
        () => Array.from(new Set(demoCourses.map((course) => course.status))),
        [],
    );

    const branches = useMemo(
        () => Array.from(new Set(demoCourses.map((course) => course.branch))),
        [],
    );

    const filteredCourses = useMemo(() => {
        const query = filters.search.trim().toLowerCase();

        return demoCourses.filter((course) => {
            const matchesSearch =
                query.length === 0 ||
                [
                    course.title,
                    course.teacher,
                    course.code,
                    course.levelName,
                    course.classCode,
                ].some((value) => value.toLowerCase().includes(query));

            const matchesStatus =
                filters.status === 'All' ||
                course.status === filters.status;

            const matchesBranch =
                filters.branch === 'All' ||
                course.branch === filters.branch;

            return matchesSearch && matchesStatus && matchesBranch;
        });
    }, [filters]);

    return (
        <StudentLayout
            studentName={studentName}
            studentId="STU-2024-0842"
            centerName="Al-Hilal Language Center"
            branchName="Riyadh – Main Branch"
            pageTitle="My Courses"
            activeNav="courses"
        >
            <Head title="My Courses" />

            <div className="space-y-5">
                <CourseFilters
                    value={filters}
                    statuses={statuses}
                    branches={branches}
                    onChange={setFilters}
                />

                <CourseGrid courses={filteredCourses} />
            </div>
        </StudentLayout>
    );
}
