import CourseInformationCard from './CourseInformationCard';
import PaymentSummaryCard from './PaymentSummaryCard';
import QuickAttendanceCard from './QuickAttendanceCard';
import type { StudentCourseDetails } from '@/types/student-course-details';

export default function CourseOverview({
    course,
}: {
    course: StudentCourseDetails;
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-[1fr_1fr] lg:items-start lg:gap-5">
            <CourseInformationCard rows={course.information} />

            <div className="space-y-4 lg:space-y-5">
                <QuickAttendanceCard data={course.attendance} />
                <PaymentSummaryCard data={course.payment} />
            </div>
        </div>
    );
}
