import type { QuickAttendanceData } from '@/types/student-course-details';

const items = [
    { key: 'present', label: 'Present', className: 'bg-[#dff6fa] text-[#05a7bd]' },
    { key: 'absent', label: 'Absent', className: 'bg-[#ffe0e0] text-[#e52931]' },
    { key: 'late', label: 'Late', className: 'bg-[#fff8e5] text-[#f1a000]' },
    { key: 'rate', label: 'Rate', className: 'bg-[#dff6fa] text-[#05a7bd]' },
] as const;

export default function QuickAttendanceCard({
    data,
}: {
    data: QuickAttendanceData;
}) {
    const values = {
        present: data.present.toString(),
        absent: data.absent.toString(),
        late: data.late.toString(),
        rate: `${data.rate}%`,
    };

    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-5 py-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <h3 className="text-[15px] font-extrabold text-[#062f85]">
                Quick Attendance
            </h3>

            <div className="mt-5 grid grid-cols-2 gap-3">
                {items.map((item) => (
                    <div
                        key={item.key}
                        className={`rounded-[11px] py-4 text-center ${item.className}`}
                    >
                        <p className="text-[21px] font-extrabold">
                            {values[item.key]}
                        </p>
                        <p className="mt-1 text-[9px] font-medium text-[#7d8794]">
                            {item.label}
                        </p>
                    </div>
                ))}
            </div>
        </section>
    );
}
