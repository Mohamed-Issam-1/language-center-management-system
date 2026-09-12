export type GradeAccent =
    | 'indigo'
    | 'violet';

export type GradeResultStatus =
    | 'published'
    | 'pending';

export type GradeCourseOption = {
    id: number;
    name: string;
    accent: GradeAccent;
};

export type GradeResult = {
    id: number;

    courseId: number;
    courseName: string;
    accent: GradeAccent;

    title: string;
    type: string;
    date: string;

    status: GradeResultStatus;

    score: number | null;
    maxScore: number;
    percentage: number | null;

    letterGrade: string | null;
    comment: string | null;
};

export type StudentGradesData = {
    courses: GradeCourseOption[];
    results: GradeResult[];
};