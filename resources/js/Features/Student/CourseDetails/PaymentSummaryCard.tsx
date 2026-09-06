import type { CoursePaymentSummary } from '@/types/student-course-details';

function money(currency: string, amount: number) {
    return `${currency} ${amount.toLocaleString('en-US')}`;
}

export default function PaymentSummaryCard({
    data,
}: {
    data: CoursePaymentSummary;
}) {
    const rows = [
        { label: 'Total Fees', value: money(data.currency, data.totalFees), className: 'text-[#2e333b]' },
        { label: 'Discount', value: money(data.currency, data.discount), className: 'text-[#04a8bf]' },
        { label: 'Paid', value: money(data.currency, data.paid), className: 'text-[#04a8bf]' },
        { label: 'Remaining', value: money(data.currency, data.remaining), className: 'text-[#e22830]' },
    ];

    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Payment Summary
            </h3>

            <dl className="mt-5 divide-y divide-[#edf0f4]">
                {rows.map((row) => (
                    <div
                        key={row.label}
                        className="flex items-center justify-between gap-4 py-[10px]"
                    >
                        <dt className="text-[11px] text-[#b0b8c4]">
                            {row.label}
                        </dt>
                        <dd className={`text-[11px] font-extrabold ${row.className}`}>
                            {row.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
