import {
    BookOpen,
    CalendarDays,
    ClipboardList,
    CreditCard,
    LayoutGrid,
    UserRound,
} from 'lucide-react';

const items = [
    { label: 'Dashboard', icon: LayoutGrid, active: true },
    { label: 'Courses', icon: BookOpen },
    { label: 'Schedule', icon: CalendarDays },
    { label: 'Attendance', icon: ClipboardList },
    { label: 'Payments', icon: CreditCard },
    { label: 'Profile', icon: UserRound },
];

export default function StudentBottomNav() {
    return (
        <nav className="fixed bottom-0 left-0 right-0 z-30 grid h-[64px] grid-cols-6 border-t border-[#e4e8ef] bg-white px-1 lg:hidden">
            {items.map(({ label, icon: Icon, active }) => (
                <button
                    key={label}
                    type="button"
                    className={[
                        'relative flex min-w-0 flex-col items-center justify-center gap-1 text-[8px] font-semibold',
                        active ? 'text-[#062f85]' : 'text-[#c1c9d5]',
                    ].join(' ')}
                >
                    <Icon size={17} strokeWidth={1.8} />
                    <span className="max-w-full truncate">{label}</span>
                    {active && (
                        <span className="absolute bottom-0 h-[3px] w-7 rounded-t-full bg-[#062f85]" />
                    )}
                </button>
            ))}
        </nav>
    );
}
