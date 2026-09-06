export type CourseDetailsTab =
    | 'overview'
    | 'schedule'
    | 'attendance'
    | 'payments';

const tabs: { key: CourseDetailsTab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'attendance', label: 'Attendance' },
    { key: 'payments', label: 'Payments' },
];

export default function CourseTabs({
    activeTab = 'overview',
    onTabChange,
}: {
    activeTab?: CourseDetailsTab;
    onTabChange?: (tab: CourseDetailsTab) => void;
}) {
    return (
        <nav
            aria-label="Course sections"
            className="grid grid-cols-4 rounded-[13px] border border-[#e5e9ef] bg-white p-1 shadow-[0_1px_4px_rgba(16,24,40,0.03)]"
        >
            {tabs.map((tab) => {
                const active = tab.key === activeTab;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => onTabChange?.(tab.key)}
                        className={[
                            'h-[39px] rounded-[9px] px-2 text-[11px] font-semibold transition sm:text-[12px]',
                            active
                                ? 'bg-[#052f98] font-extrabold text-white'
                                : 'text-[#656e7c] hover:bg-[#f5f7fb] hover:text-[#25364f]',
                        ].join(' ')}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </nav>
    );
}
