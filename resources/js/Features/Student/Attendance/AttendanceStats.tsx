import type { AttendanceSummary } from '@/types/student-attendance';

const cards = [
    {
        key: 'averageRate',
        label: 'Avg Rate',
        tone: 'cyan',
        valueClass: 'cyan-text',
        suffix: '%',
    },
    {
        key: 'present',
        label: 'Present',
        tone: 'cyan',
        valueClass: 'cyan-text',
        suffix: '',
    },
    {
        key: 'absent',
        label: 'Absent',
        tone: 'red',
        valueClass: 'red-text',
        suffix: '',
    },
    {
        key: 'late',
        label: 'Late',
        tone: 'yellow',
        valueClass: 'yellow-text',
        suffix: '',
    },
    {
        key: 'excused',
        label: 'Excused',
        tone: 'blue',
        valueClass: 'blue-text',
        suffix: '',
    },
] as const;

export default function AttendanceStats({
    summary,
}: {
    summary: AttendanceSummary;
}) {
    return (
        <section className="attendance-stats-grid">
            {cards.map((card) => (
                <article
                    key={card.key}
                    className={`record-stat centered ${card.tone}`}
                >
                    <p
                        className={`record-stat-value ${card.valueClass}`}
                    >
                        {summary[card.key]}
                        {card.suffix}
                    </p>
                    <p className="record-stat-label">
                        {card.label}
                    </p>
                </article>
            ))}
        </section>
    );
}
