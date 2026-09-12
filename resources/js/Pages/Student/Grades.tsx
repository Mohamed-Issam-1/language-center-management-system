import '../../../css/student-academics.css';

import GradeFilters from '@/Features/Student/Grades/GradeFilters';
import GradeResultCard from '@/Features/Student/Grades/GradeResultCard';
import GradeSummary from '@/Features/Student/Grades/GradeSummary';
import StudentLayout from '@/Layouts/StudentLayout';
import type {
    GradeCourseOption,
    GradeResult,
    StudentGradesData,
} from '@/types/student-grades';
import { Head } from '@inertiajs/react';
import {
    useMemo,
    useState,
} from 'react';

const courses: GradeCourseOption[] = [
    {
        id: 1,
        name: 'English – Intermediate',
        accent: 'indigo',
    },
    {
        id: 2,
        name: 'French – Beginner',
        accent: 'violet',
    },
];

const results: GradeResult[] = [
    {
        id: 1,
        courseId: 1,
        courseName:
            'English – Intermediate',
        accent: 'indigo',

        title: 'Midterm Exam',
        type: 'Midterm',
        date: 'Aug 15, 2026',

        status: 'published',

        score: 84,
        maxScore: 100,
        percentage: 84,

        letterGrade: 'B+',

        comment:
            'Good performance overall. Work on writing section.',
    },
    {
        id: 2,
        courseId: 1,
        courseName:
            'English – Intermediate',
        accent: 'indigo',

        title: 'Quiz 1 – Grammar',
        type: 'Quiz',
        date: 'Aug 5, 2026',

        status: 'published',

        score: 18,
        maxScore: 20,
        percentage: 90,

        letterGrade: 'A',
        comment: null,
    },
    {
        id: 3,
        courseId: 1,
        courseName:
            'English – Intermediate',
        accent: 'indigo',

        title: 'Quiz 2 – Reading',
        type: 'Quiz',
        date: 'Aug 17, 2026',

        status: 'published',

        score: 15,
        maxScore: 20,
        percentage: 75,

        letterGrade: 'B+',
        comment: null,
    },
    {
        id: 4,
        courseId: 1,
        courseName:
            'English – Intermediate',
        accent: 'indigo',

        title: 'Final Exam',
        type: 'Final',
        date: 'Dec 18, 2026',

        status: 'pending',

        /*
         * No score is placed in the frontend mock.
         * This mirrors the SRS rule that unpublished
         * results must not be exposed.
         */
        score: null,
        maxScore: 100,
        percentage: null,

        letterGrade: null,
        comment: null,
    },
    {
        id: 5,
        courseId: 2,
        courseName:
            'French – Beginner',
        accent: 'violet',

        title: 'Midterm Exam',
        type: 'Midterm',
        date: 'Aug 25, 2026',

        status: 'published',

        score: 91,
        maxScore: 100,
        percentage: 91,

        letterGrade: 'A',

        comment:
            'Excellent work! Keep it up.',
    },
    {
        id: 6,
        courseId: 2,
        courseName:
            'French – Beginner',
        accent: 'violet',

        title: 'Quiz 1 – Vocabulary',
        type: 'Quiz',
        date: 'Aug 9, 2026',

        status: 'published',

        score: 20,
        maxScore: 20,
        percentage: 100,

        letterGrade: 'A+',
        comment: null,
    },
];

const demoGradesData: StudentGradesData = {
    courses,
    results,
};

export default function Grades() {
    const [
        selectedCourse,
        setSelectedCourse,
    ] = useState('all');

    const filteredResults =
        useMemo(() => {
            if (
                selectedCourse ===
                'all'
            ) {
                return demoGradesData.results;
            }

            return demoGradesData.results.filter(
                (result) =>
                    result.courseId.toString() ===
                    selectedCourse,
            );
        }, [selectedCourse]);

    return (
        <StudentLayout
            studentName="Mohammad Demo Student"
            studentId="99000001"
            centerName="Beatty LLC Language Center"
            branchName="Main Branch"
            pageTitle="My Grades"
            activeNav="grades"
            fluid
        >
            <Head title="My Grades" />

            <div className="student-academic-page grades-page">
                <GradeSummary
                    results={
                        filteredResults
                    }
                />

                <GradeFilters
                    courses={
                        demoGradesData.courses
                    }
                    selectedCourse={
                        selectedCourse
                    }
                    onChange={
                        setSelectedCourse
                    }
                />

                <div className="grade-results-list">
                    {filteredResults.map(
                        (result) => (
                            <GradeResultCard
                                key={
                                    result.id
                                }
                                result={
                                    result
                                }
                            />
                        ),
                    )}
                </div>
            </div>
        </StudentLayout>
    );
}