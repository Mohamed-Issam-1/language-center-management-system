import type { PaymentSummary } from '@/types/student-payments';

function money(currency: string, value: number) {
    return `${currency} ${value.toLocaleString('en-US')}`;
}

const cards = [
    {
        key: 'totalFees',
        label: 'Total Fees',
        valueClass: '',
    },
    {
        key: 'discount',
        label: 'Discount',
        valueClass: 'cyan-text',
    },
    {
        key: 'totalPaid',
        label: 'Total Paid',
        valueClass: 'cyan-text',
    },
    {
        key: 'remaining',
        label: 'Remaining',
        valueClass: 'yellow-text',
    },
    {
        key: 'overdue',
        label: 'Overdue',
        valueClass: 'red-text',
    },
] as const;

export default function PaymentStats({
    summary,
}: {
    summary: PaymentSummary;
}) {
    return (
        <section className="payment-stats-grid">
            {cards.map((card) => (
                <article key={card.key} className="record-stat">
                    <p className="record-stat-label">
                        {card.label}
                    </p>
                    <p
                        className={`record-stat-value ${card.valueClass}`}
                    >
                        {money(
                            summary.currency,
                            summary[card.key],
                        )}
                    </p>
                </article>
            ))}
        </section>
    );
}
