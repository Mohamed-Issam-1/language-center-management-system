export type ScheduleSessionStatus =
    | 'Upcoming'
    | 'Completed'
    | 'Cancelled';

export type ScheduleCourseOption = {
    id: number;
    label: string;
};

export type ScheduleSession = {
    id: number;
    courseId: number;
    courseName: string;
    teacher: string;
    room: string;
    date: string; // YYYY-MM-DD
    time: string;
    calendarLabel: string;
    status: ScheduleSessionStatus;
};

export type StudentSchedulePageData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    today: string;

    courseOptions: ScheduleCourseOption[];

    sessions: ScheduleSession[];
};
