export type PaymentStatus =
    | 'Paid'
    | 'Overdue'
    | 'Pending';

export type PaymentSummary = {
    currency: string;

    totalFees: number;
    discount: number;
    totalPaid: number;
    remaining: number;
    overdue: number;
};

export type PaymentInstallmentRecord = {
    id: number;

    receiptNo: string | null;
    receiptHref: string | null;

    course: string;

    amount: number;

    dueDate: string;
    dueDateSort: string;

    paidDate: string | null;
    method: string | null;

    status: PaymentStatus;
};

export type StudentPaymentsPageData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    summary: PaymentSummary;

    installments: PaymentInstallmentRecord[];
};

export type PaymentReceiptData = {
    student: {
        name: string;
        studentId: string;
    };

    center: {
        name: string;
        branch: string;
    };

    receipt: {
        receiptNo: string;
        currency: string;
        issuedAt: string;
        status: 'Paid';

        issuedTo: {
            name: string;
            studentId: string;
            email: string;
            phone: string;
        };

        paymentDetails: {
            paymentDate: string;
            dueDate: string;
            method: string;
        };

        course: {
            name: string;
            level: string;
            section: string;
            teacher: string;
            branch: string;
            startDate: string;
            endDate: string;
            enrollmentDate: string;
        };

        payment: {
            type: string;
            installmentAmount: number;
            totalCourseFees: number;
            totalPaidToDate: number;
            remainingBalance: number;
            amountReceived: number;
        };

        receivedBy: {
            name: string;
            role: string;
            branch: string;
        };
    };
};