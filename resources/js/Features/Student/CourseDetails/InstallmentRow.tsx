import { Link } from "@inertiajs/react";
import { ReceiptText } from "lucide-react";
import type { CourseInstallment } from "@/types/student-course-details";

const statusClasses = {
  Paid: "bg-[#ddf7fb] text-[#03a7bd]",
  Overdue: "bg-[#ffe0e0] text-[#e52b34]",
  Upcoming: "bg-[#e8edf8] text-[#07358a]",
};

function money(currency: string, amount: number) {
  return `${currency} ${amount.toLocaleString("en-US")}`;
}

export default function InstallmentRow({
  installment,
}: {
  installment: CourseInstallment;
}) {
  return (
    <article className="flex flex-col gap-3 rounded-[11px] bg-[#f4f6fb] px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="text-[13px] font-extrabold text-[#2f343c]">
          {money(installment.currency ?? "SAR", installment.amount)}
        </p>

        <p className="mt-1 text-[10px] text-[#aeb7c4]">
          Due: {installment.dueDate}
          {installment.paidDate ? ` · Paid: ${installment.paidDate}` : ""}
        </p>

        {installment.paymentMethod && (
          <p className="mt-1 text-[10px] text-[#c0c7d0]">
            Via {installment.paymentMethod}
          </p>
        )}
      </div>

      <div className="flex shrink-0 items-center justify-between gap-3 sm:flex-col sm:items-end sm:gap-1.5">
        <span
          className={[
            "rounded-full px-3 py-1 text-[9px] font-bold",
            statusClasses[installment.status],
          ].join(" ")}
        >
          {installment.status}
        </span>

        {installment.receiptHref && (
          <Link
            href={installment.receiptHref}
            className="inline-flex items-center gap-1 text-[9px] font-bold text-[#062f85] hover:underline"
          >
            <ReceiptText size={12} />
            View Receipt
          </Link>
        )}
      </div>
    </article>
  );
}
