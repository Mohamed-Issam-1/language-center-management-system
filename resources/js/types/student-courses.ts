export type StudentCourseStatus =
    | 'Active'
    | 'Completed'
    | 'Withdrawn'
    | 'Transferred'
    | 'Cancelled';

export type StudentCourseAccent = 'indigo' | 'violet';

export type StudentCoursesPageData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    courses: StudentCourse[];
};

export type StudentCourse = {
    id: number;
    code: string;
    title: string;
    levelName: string;
    classCode: string;
    teacher: string;
    branch: string;
    schedule: string;
    duration: string;
    attendance: number;
    status: StudentCourseStatus;
    accent: StudentCourseAccent;
    detailsHref?: string;
};
