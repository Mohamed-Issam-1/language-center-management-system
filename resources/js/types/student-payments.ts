export type PaymentStatus = 'Paid' | 'Overdue' | 'Pending';

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
    course: string;
    amount: number;
    dueDate: string;
    dueDateSort: string;
    paidDate: string | null;
    method: string | null;
    status: PaymentStatus;
};
