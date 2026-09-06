import type { CourseInformationRow } from '@/types/student-course-details';

export default function CourseInformationCard({
    rows,
}: {
    rows: CourseInformationRow[];
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)] sm:px-6">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Course Information
            </h3>

            <dl className="mt-5 divide-y divide-[#edf0f4]">
                {rows.map((row) => (
                    <div
                        key={row.label}
                        className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] items-center gap-4 py-[13px]"
                    >
                        <dt className="text-[11px] text-[#aeb7c4]">
                            {row.label}
                        </dt>
                        <dd className="text-right text-[11px] font-extrabold leading-5 text-[#30353d]">
                            {row.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
