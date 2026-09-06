import CourseFilters, {
    type CourseFilterState,
} from '@/Features/Student/Courses/CourseFilters';
import CourseGrid from '@/Features/Student/Courses/CourseGrid';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type {
    StudentCourse,
    StudentCoursesPageData,
} from '@/types/student-courses';
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
        detailsHref: '/demo/courses/2',
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
        detailsHref: '/demo/courses/2',
    },
];

type MyCoursesPageProps = PageProps & {
    studentCourses?: StudentCoursesPageData;
};

export default function MyCourses() {
    const page =
        usePage<MyCoursesPageProps>();

    const demoMode =
        page.url.startsWith('/demo');

    const pageData: StudentCoursesPageData =
        demoMode
            ? {
                  student: {
                      name: 'Mohammad Znaid',
                      studentId:
                          'STU-2024-0842',
                  },

                  center: {
                      name:
                          'Al-Hilal Language Center',
                      branch:
                          'Riyadh – Main Branch',
                  },

                  courses:
                      demoCourses,
              }
            : page.props.studentCourses!;

    const courses =
        pageData?.courses ?? [];

    const [filters, setFilters] =
        useState<CourseFilterState>({
            search: '',
            status: 'All',
            branch: 'All',
        });

    const statuses = useMemo(
        () =>
            Array.from(
                new Set(
                    courses.map(
                        (course) =>
                            course.status,
                    )
                )
            ),
        [courses],
    );

    const branches = useMemo(
        () =>
            Array.from(
                new Set(
                    courses.map(
                        (course) =>
                            course.branch,
                    )
                )
            ),
        [courses],
    );

    const filteredCourses =
        useMemo(() => {
            const query =
                filters.search
                    .trim()
                    .toLowerCase();

            return courses.filter(
                (course) => {
                    const matchesSearch =
                        query.length === 0 ||
                        [
                            course.title,
                            course.teacher,
                            course.code,
                            course.levelName,
                            course.classCode,
                        ].some((value) =>
                            value
                                .toLowerCase()
                                .includes(
                                    query,
                                )
                        );

                    const matchesStatus =
                        filters.status ===
                            'All' ||
                        course.status ===
                            filters.status;

                    const matchesBranch =
                        filters.branch ===
                            'All' ||
                        course.branch ===
                            filters.branch;

                    return (
                        matchesSearch &&
                        matchesStatus &&
                        matchesBranch
                    );
                }
            );
        }, [courses, filters]);

    return (
        <StudentLayout
            studentName={
                pageData.student.name
            }
            studentId={
                pageData.student
                    .studentId
            }
            centerName={
                pageData.center.name
            }
            branchName={
                pageData.center.branch
            }
            pageTitle="My Courses"
            activeNav="courses"
        >
            <Head title="My Courses" />

            <div className="space-y-5">
                <CourseFilters
                    value={filters}
                    statuses={statuses}
                    branches={branches}
                    onChange={
                        setFilters
                    }
                />

                <CourseGrid
                    courses={
                        filteredCourses
                    }
                />
            </div>
        </StudentLayout>
    );
}