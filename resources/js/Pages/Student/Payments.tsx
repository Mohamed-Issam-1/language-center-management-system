import '../../../css/student-records.css';

import InstallmentSchedule from '@/Features/Student/Payments/InstallmentSchedule';
import OverduePaymentAlert from '@/Features/Student/Payments/OverduePaymentAlert';
import PaymentInfoBanner from '@/Features/Student/Payments/PaymentInfoBanner';
import PaymentStats from '@/Features/Student/Payments/PaymentStats';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type {
    StudentPaymentsPageData,
} from '@/types/student-payments';
import {
    Head,
    usePage,
} from '@inertiajs/react';

const demoPayments: StudentPaymentsPageData = {
    student: {
        name: 'Mohammad Znaid',
        studentId:
            'STU-2026-0842',
    },

    center: {
        name:
            'Al-Hilal Language Center',
        branch:
            'Gaza – Palestine',
    },

    summary: {
        currency: '₪',
        totalFees: 3000,
        discount: 100,
        totalPaid: 2000,
        remaining: 900,
        overdue: 600,
    },

    installments: [
        {
            id: 1,
            receiptNo:
                'RCP-2026-1001',
            receiptHref: null,
            course:
                'English – Intermediate',
            amount: 600,
            dueDate:
                'Sep 5, 2026',
            dueDateSort:
                '2026-09-05',
            paidDate:
                'Sep 5, 2026',
            method: 'Cash',
            status: 'Paid',
        },
        {
            id: 2,
            receiptNo:
                'RCP-2026-1045',
            receiptHref: null,
            course:
                'English – Intermediate',
            amount: 600,
            dueDate:
                'Oct 5, 2026',
            dueDateSort:
                '2026-10-05',
            paidDate:
                'Oct 8, 2026',
            method:
                'Bank Transfer',
            status: 'Paid',
        },
        {
            id: 3,
            receiptNo: null,
            receiptHref: null,
            course:
                'English – Intermediate',
            amount: 600,
            dueDate:
                'Aug 25, 2026',
            dueDateSort:
                '2026-08-25',
            paidDate: null,
            method: null,
            status: 'Overdue',
        },
        {
            id: 4,
            receiptNo:
                'RCP-2026-1022',
            receiptHref: null,
            course:
                'French – Beginner',
            amount: 400,
            dueDate:
                'Oct 1, 2026',
            dueDateSort:
                '2026-10-01',
            paidDate:
                'Oct 1, 2026',
            method: 'Cash',
            status: 'Paid',
        },
        {
            id: 5,
            receiptNo:
                'RCP-2026-1089',
            receiptHref: null,
            course:
                'French – Beginner',
            amount: 400,
            dueDate:
                'Nov 1, 2026',
            dueDateSort:
                '2026-11-01',
            paidDate:
                'Nov 3, 2026',
            method: 'Cash',
            status: 'Paid',
        },
        {
            id: 6,
            receiptNo: null,
            receiptHref: null,
            course:
                'French – Beginner',
            amount: 400,
            dueDate:
                'Dec 1, 2026',
            dueDateSort:
                '2026-12-01',
            paidDate: null,
            method: null,
            status: 'Pending',
        },
    ],
};

type PaymentsPageProps =
    PageProps & {
        studentPayments?: StudentPaymentsPageData;
    };

export default function Payments() {
    const page =
        usePage<PaymentsPageProps>();

    const demoMode =
        page.url.startsWith(
            '/demo'
        );

    const data =
        demoMode
            ? demoPayments
            : page.props
                  .studentPayments;

    if (!data) {
        throw new Error(
            'Student payment data was not provided by Laravel.',
        );
    }

    return (
        <StudentLayout
            studentName={
                data.student.name
            }
            studentId={
                data.student.studentId
            }
            centerName={
                data.center.name
            }
            branchName={
                data.center.branch
            }
            pageTitle="Payments"
            activeNav="payments"
            fluid
        >
            <Head title="Payments" />

            <div className="student-records-page records-stack">
                <PaymentInfoBanner />

                <PaymentStats
                    summary={
                        data.summary
                    }
                />

                {data.summary.overdue >
                    0 && (
                    <OverduePaymentAlert
                        summary={
                            data.summary
                        }
                    />
                )}

                <InstallmentSchedule
                    records={
                        data.installments
                    }
                    currency={
                        data.summary
                            .currency
                    }
                />
            </div>
        </StudentLayout>
    );
}