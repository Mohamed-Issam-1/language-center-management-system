import '../../../css/student-records.css';

import InstallmentSchedule from '@/Features/Student/Payments/InstallmentSchedule';
import OverduePaymentAlert from '@/Features/Student/Payments/OverduePaymentAlert';
import PaymentInfoBanner from '@/Features/Student/Payments/PaymentInfoBanner';
import PaymentStats from '@/Features/Student/Payments/PaymentStats';
import StudentLayout from '@/Layouts/StudentLayout';
import type {
    PaymentInstallmentRecord,
    PaymentSummary,
} from '@/types/student-payments';
import { Head, usePage } from '@inertiajs/react';

const summary: PaymentSummary = {
    currency: 'SAR',
    totalFees: 3000,
    discount: 100,
    totalPaid: 2000,
    remaining: 900,
    overdue: 600,
};

const installments: PaymentInstallmentRecord[] = [
    {
        id: 1,
        receiptNo: 'RCP-2024-1001',
        course: 'English – Intermediate',
        amount: 600,
        dueDate: 'Sep 5, 2024',
        dueDateSort: '2024-09-05',
        paidDate: 'Sep 5, 2024',
        method: 'Cash',
        status: 'Paid',
    },
    {
        id: 2,
        receiptNo: 'RCP-2024-1045',
        course: 'English – Intermediate',
        amount: 600,
        dueDate: 'Oct 5, 2024',
        dueDateSort: '2024-10-05',
        paidDate: 'Oct 8, 2024',
        method: 'Bank Transfer',
        status: 'Paid',
    },
    {
        id: 3,
        receiptNo: null,
        course: 'English – Intermediate',
        amount: 600,
        dueDate: 'Nov 5, 2024',
        dueDateSort: '2024-11-05',
        paidDate: null,
        method: null,
        status: 'Overdue',
    },
    {
        id: 4,
        receiptNo: 'RCP-2024-1022',
        course: 'French – Beginner',
        amount: 400,
        dueDate: 'Oct 1, 2024',
        dueDateSort: '2024-10-01',
        paidDate: 'Oct 1, 2024',
        method: 'Cash',
        status: 'Paid',
    },
    {
        id: 5,
        receiptNo: 'RCP-2024-1089',
        course: 'French – Beginner',
        amount: 400,
        dueDate: 'Nov 1, 2024',
        dueDateSort: '2024-11-01',
        paidDate: 'Nov 3, 2024',
        method: 'Cash',
        status: 'Paid',
    },
    {
        id: 6,
        receiptNo: null,
        course: 'French – Beginner',
        amount: 400,
        dueDate: 'Dec 1, 2024',
        dueDateSort: '2024-12-01',
        paidDate: null,
        method: null,
        status: 'Pending',
    },
];

type OptionalAuthProps = {
    auth?: {
        user?: {
            name?: string;
        } | null;
    };
};

export default function Payments() {
    const page = usePage();
    const props = page.props as OptionalAuthProps;

    const studentName =
        props.auth?.user?.name || 'Mohammad Znaid';

    return (
        <StudentLayout
            studentName={studentName}
            studentId="STU-2024-0842"
            centerName="Al-Hilal Language Center"
            branchName="Riyadh – Main Branch"
            pageTitle="Payments"
            activeNav="payments"
        >
            <Head title="Payments" />

            <div className="student-records-page records-stack">
                <PaymentInfoBanner />
                <PaymentStats summary={summary} />
                <OverduePaymentAlert summary={summary} />
                <InstallmentSchedule
                    records={installments}
                    currency={summary.currency}
                />
            </div>
        </StudentLayout>
    );
}
