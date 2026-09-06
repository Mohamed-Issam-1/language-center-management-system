import type { CoursePaymentSummary } from '@/types/student-course-details';

function money(currency: string, amount: number) {
    return `${currency} ${amount.toLocaleString('en-US')}`;
}

const cards = [
    { key: 'totalFees', label: 'Total Fees', valueClass: 'text-[#282d34]' },
    { key: 'discount', label: 'Discount', valueClass: 'text-[#04a8bf]' },
    { key: 'paid', label: 'Paid', valueClass: 'text-[#04a8bf]' },
    { key: 'remaining', label: 'Remaining', valueClass: 'text-[#e32830]' },
] as const;

export default function PaymentStats({
    data,
}: {
    data: CoursePaymentSummary;
}) {
    const values = {
        totalFees: data.totalFees,
        discount: data.discount,
        paid: data.paid,
        remaining: data.remaining,
    };

    return (
        <section className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            {cards.map((card) => (
                <article
                    key={card.key}
                    className="rounded-[14px] border border-[#e7eaf0] bg-white px-4 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]"
                >
                    <p className="text-[9px] font-extrabold uppercase tracking-wide text-[#aeb7c4]">
                        {card.label}
                    </p>
                    <p className={`mt-2 text-[18px] font-extrabold ${card.valueClass}`}>
                        {money(data.currency, values[card.key])}
                    </p>
                </article>
            ))}
        </section>
    );
}
