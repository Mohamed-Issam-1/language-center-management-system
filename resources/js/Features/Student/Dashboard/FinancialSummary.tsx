import type { FinancialSummaryData } from '@/types/student-dashboard';
import { Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

function formatMoney(currency: string, value: number) {
    return `${currency} ${value.toLocaleString('en-US')}`;
}

export default function FinancialSummary({
    data,
}: {
    data: FinancialSummaryData;
}) {
    const { url } = usePage();

    const paymentsHref = url.startsWith('/demo')
        ? '/demo/payments'
        : '/payments';

    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Financial Summary
            </h3>

            <div className="mt-5 divide-y divide-[#eef0f4] text-[11px]">
                <div className="flex justify-between py-3">
                    <span className="text-[#7f8998]">
                        Total Fees
                    </span>

                    <span className="font-extrabold text-[#292e35]">
                        {formatMoney(
                            data.currency,
                            data.totalFees,
                        )}
                    </span>
                </div>

                <div className="flex justify-between py-3">
                    <span className="text-[#7f8998]">
                        Total Paid
                    </span>

                    <span className="font-extrabold text-[#03a8bf]">
                        {formatMoney(
                            data.currency,
                            data.totalPaid,
                        )}
                    </span>
                </div>

                <div className="flex justify-between py-3">
                    <span className="text-[#7f8998]">
                        Remaining
                    </span>

                    <span className="font-extrabold text-[#535a64]">
                        {formatMoney(
                            data.currency,
                            data.remaining,
                        )}
                    </span>
                </div>
            </div>

            {data.overdue > 0 && (
                <div className="mt-3 flex items-center justify-between rounded-[10px] bg-[#ffe0e0] px-3 py-3 text-[11px] font-extrabold text-[#e62d36]">
                    <span className="flex items-center gap-1">
                        <TriangleAlert size={13} />
                        Overdue Installments
                    </span>

                    <span>
                        {formatMoney(
                            data.currency,
                            data.overdue,
                        )}
                    </span>
                </div>
            )}

            {data.nextInstallment !== null &&
                data.nextInstallmentDue !== null && (
                    <div className="mt-3 flex items-center justify-between rounded-[10px] bg-[#fff8e5] px-3 py-3">
                        <div>
                            <p className="text-[10px] font-extrabold text-[#f0a400]">
                                Next Installment Due
                            </p>

                            <p className="mt-1 text-[10px] text-[#985e12]">
                                {data.nextInstallmentCourse ??
                                    'Upcoming installment'}
                            </p>
                        </div>

                        <div className="text-right">
                            <p className="text-[13px] font-extrabold text-[#874400]">
                                {formatMoney(
                                    data.currency,
                                    data.nextInstallment,
                                )}
                            </p>

                            <p className="mt-1 text-[9px] text-[#f0a400]">
                                {data.nextInstallmentDue}
                            </p>
                        </div>
                    </div>
                )}

            <Link
                href={paymentsHref}
                className="mt-4 flex h-10 w-full items-center justify-center rounded-[9px] bg-[#e8edf8] text-[11px] font-extrabold text-[#062f85] transition hover:bg-[#dde5f7]"
            >
                View Full Payment Details →
            </Link>
        </section>
    );
}