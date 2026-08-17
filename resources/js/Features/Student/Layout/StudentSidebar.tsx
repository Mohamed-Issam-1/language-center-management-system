import ApplicationLogo from '@/Components/ApplicationLogo';
import {
    BookOpen,
    CalendarDays,
    ClipboardList,
    CreditCard,
    LayoutGrid,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type SidebarItem = {
    label: string;
    icon: LucideIcon;
    active?: boolean;
};

const items: SidebarItem[] = [
    { label: 'Dashboard', icon: LayoutGrid, active: true },
    { label: 'My Courses', icon: BookOpen },
    { label: 'My Schedule', icon: CalendarDays },
    { label: 'My Attendance', icon: ClipboardList },
    { label: 'Payments', icon: CreditCard },
    { label: 'Profile', icon: UserRound },
];

function initials(name: string) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export default function StudentSidebar({
    studentName,
    studentId,
    centerName,
    branchName,
}: {
    studentName: string;
    studentId: string;
    centerName: string;
    branchName: string;
}) {
    return (
        <aside className="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col border-r border-[#e8ebf2] bg-white lg:flex">
            <div className="px-5 pb-4 pt-5">
                <ApplicationLogo className="h-[50px] w-auto" />

                <div className="mt-3 inline-flex rounded-full bg-[#e9eefb] px-4 py-1 text-[13px] font-bold text-[#062f85]">
                    Student Portal
                </div>

                <div className="mt-3 rounded-lg bg-[#e8edf8] px-3 py-2.5">
                    <p className="text-[12px] font-bold leading-4 text-[#06348a]">
                        {centerName}
                    </p>
                    <p className="mt-0.5 text-[10px] text-[#5573aa]">
                        {branchName}
                    </p>
                </div>
            </div>

            <div className="border-t border-[#eef0f5]" />

            <nav className="flex-1 px-2.5 py-4">
                <div className="space-y-1.5">
                    {items.map(({ label, icon: Icon, active }) => (
                        <button
                            key={label}
                            type="button"
                            className={[
                                'flex h-[42px] w-full items-center gap-3 rounded-lg px-3 text-left text-[14px] font-medium transition',
                                active
                                    ? 'bg-[#e8edf8] font-bold text-[#062f85]'
                                    : 'text-[#9da9bc] hover:bg-[#f6f8fc] hover:text-[#4f6079]',
                            ].join(' ')}
                        >
                            <Icon
                                size={17}
                                strokeWidth={1.8}
                                className={active ? 'text-[#123e99]' : ''}
                            />
                            <span>{label}</span>
                            {active && (
                                <span className="ml-auto h-1.5 w-1.5 rounded-full bg-[#0c2f83]" />
                            )}
                        </button>
                    ))}
                </div>
            </nav>

            <div className="border-t border-[#eef0f5] p-2.5">
                <div className="flex items-center gap-3 rounded-xl bg-[#f5f7fb] px-3 py-3">
                    <div className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[#052e86] text-[12px] font-bold text-white">
                        {initials(studentName)}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-[12px] font-bold text-[#252b36]">
                            {studentName}
                        </p>
                        <p className="mt-0.5 truncate text-[10px] text-[#a6afbd]">
                            {studentId}
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    );
}
