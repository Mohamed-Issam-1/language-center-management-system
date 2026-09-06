import type { QuickAttendanceData } from '@/types/student-course-details';

const items = [
    {
        key: 'rate',
        label: 'Attendance Rate',
        card: 'bg-[#dff6fa]',
        value: 'text-[#04a8bf]',
    },
    {
        key: 'present',
        label: 'Present',
        card: 'bg-[#dff6fa]',
        value: 'text-[#04a8bf]',
    },
    {
        key: 'absent',
        label: 'Absent',
        card: 'bg-[#ffe0e0]',
        value: 'text-[#e42b33]',
    },
    {
        key: 'late',
        label: 'Late',
        card: 'bg-[#fff9e8]',
        value: 'text-[#ef9900]',
    },
] as const;

export default function AttendanceStats({
    data,
}: {
    data: QuickAttendanceData;
}) {
    const values = {
        rate: `${data.rate}%`,
        present: data.present.toString(),
        absent: data.absent.toString(),
        late: data.late.toString(),
    };

    return (
        <section className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            {items.map((item) => (
                <article
                    key={item.key}
                    className={`rounded-[14px] px-4 py-5 text-center shadow-[0_2px_8px_rgba(16,24,40,0.05)] ${item.card}`}
                >
                    <p className={`text-[23px] font-extrabold ${item.value}`}>
                        {values[item.key]}
                    </p>
                    <p className="mt-1 text-[10px] text-[#747e8c]">
                        {item.label}
                    </p>
                </article>
            ))}
        </section>
    );
}
