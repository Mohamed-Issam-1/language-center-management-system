import InstallmentRow from './InstallmentRow';
import type { CourseInstallment } from '@/types/student-course-details';

export default function InstallmentsCard({
    installments,
}: {
    installments: CourseInstallment[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)] sm:px-6">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Installments
            </h3>

            <div className="mt-4 space-y-3">
                {installments.map((installment) => (
                    <InstallmentRow
                        key={installment.id}
                        installment={installment}
                    />
                ))}
            </div>
        </section>
    );
}
