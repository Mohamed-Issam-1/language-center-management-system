export type StudentNotificationType =
    | 'grade'
    | 'attendance'
    | 'payment'
    | 'general';

export type StudentNotification = {
    id: number;

    type: StudentNotificationType;

    title: string;
    message: string;
    date: string;

    initiallyRead: boolean;
};