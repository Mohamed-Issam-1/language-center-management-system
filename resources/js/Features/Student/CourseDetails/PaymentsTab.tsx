import InstallmentsCard from './InstallmentsCard';
import PaymentStats from './PaymentStats';
import type {
    CourseInstallment,
    CoursePaymentSummary,
} from '@/types/student-course-details';

export default function PaymentsTab({
    summary,
    installments,
}: {
    summary: CoursePaymentSummary;
    installments: CourseInstallment[];
}) {
    return (
        <div className="space-y-4 lg:space-y-5">
            <PaymentStats data={summary} />
            <InstallmentsCard installments={installments} />
        </div>
    );
}
