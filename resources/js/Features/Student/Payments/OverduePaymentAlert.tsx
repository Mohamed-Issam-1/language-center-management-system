import { TriangleAlert } from 'lucide-react';
import type { PaymentSummary } from '@/types/student-payments';

export default function OverduePaymentAlert({
    summary,
}: {
    summary: PaymentSummary;
}) {
    return (
        <section className="records-alert danger payment-overdue-alert">
            <TriangleAlert
                size={20}
                className="records-alert-icon"
            />

            <div>
                <h2 className="records-alert-title">
                    Overdue Payment
                </h2>
                <p className="records-alert-copy">
                    You have {summary.currency}{' '}
                    {summary.overdue.toLocaleString('en-US')} in
                    overdue installments. Please contact the
                    administration office to settle your payment.
                </p>
            </div>
        </section>
    );
}
