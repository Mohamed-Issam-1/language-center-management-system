import {
    BookOpen,
    ClipboardList,
    CreditCard,
    FileText,
} from 'lucide-react';
import type { DashboardStat } from '@/types/student-dashboard';

const iconMap = {
    courses: BookOpen,
    attendance: ClipboardList,
    paid: CreditCard,
    outstanding: FileText,
};

const toneMap = {
    blue: {
        value: 'text-[#082d82]',
        icon: 'bg-[#e9eef9] text-[#123d98]',
    },
    cyan: {
        value: 'text-[#03abc2]',
        icon: 'bg-[#e3f8fb] text-[#08acc0]',
    },
    red: {
        value: 'text-[#e3222c]',
        icon: 'bg-[#ffe6e7] text-[#ef3340]',
    },
};

function StatCard({ stat }: { stat: DashboardStat }) {
    const Icon = iconMap[stat.icon];
    const tone = toneMap[stat.tone];

    return (
        <article className="flex min-h-[85px] items-center justify-between rounded-[15px] border border-[#e7eaf0] bg-white px-4 py-4 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <div>
                <p className="text-[10px] font-semibold text-[#a9b2c0] sm:text-[11px]">
                    {stat.label}
                </p>
                <p className={`mt-2 text-[19px] font-extrabold ${tone.value}`}>
                    {stat.value}
                </p>
            </div>
            <div
                className={`grid h-10 w-10 shrink-0 place-items-center rounded-[11px] ${tone.icon}`}
            >
                <Icon size={19} strokeWidth={1.8} />
            </div>
        </article>
    );
}

export default function DashboardStats({ stats }: { stats: DashboardStat[] }) {
    return (
        <section className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            {stats.map((stat) => (
                <StatCard key={stat.label} stat={stat} />
            ))}
        </section>
    );
}
