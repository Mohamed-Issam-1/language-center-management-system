import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    ClipboardList,
    CreditCard,
    LayoutGrid,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { StudentNavKey } from './StudentSidebar';

type BottomItem = {
    key: StudentNavKey;
    label: string;
    icon: LucideIcon;
    authenticatedHref: string;
    demoHref: string;
};

const items: BottomItem[] = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: LayoutGrid,
        authenticatedHref: '/dashboard',
        demoHref: '/demo/dashboard',
    },
    {
        key: 'courses',
        label: 'My',
        icon: BookOpen,
        authenticatedHref: '/my-courses',
        demoHref: '/demo/courses',
    },
    {
        key: 'schedule',
        label: 'My',
        icon: CalendarDays,
        authenticatedHref: '/my-schedule',
        demoHref: '/demo/schedule',
    },
    {
        key: 'attendance',
        label: 'My',
        icon: ClipboardList,
        authenticatedHref: '#',
        demoHref: '#',
    },
    {
        key: 'payments',
        label: 'Payments',
        icon: CreditCard,
        authenticatedHref: '#',
        demoHref: '#',
    },
    {
        key: 'profile',
        label: 'Profile',
        icon: UserRound,
        authenticatedHref: '/profile',
        demoHref: '#',
    },
];

export default function StudentBottomNav({
    activeNav,
}: {
    activeNav: StudentNavKey;
}) {
    const { url } = usePage();
    const demoMode = url.startsWith('/demo');

    return (
        <nav className="fixed bottom-0 left-0 right-0 z-30 grid h-[64px] grid-cols-6 border-t border-[#e4e8ef] bg-white px-1 lg:hidden">
            {items.map((item) => {
                const Icon = item.icon;
                const active = activeNav === item.key;
                const href = demoMode
                    ? item.demoHref
                    : item.authenticatedHref;

                return (
                    <Link
                        key={item.key}
                        href={href}
                        className={[
                            'relative flex min-w-0 flex-col items-center justify-center gap-1 text-[8px] font-semibold',
                            active
                                ? 'text-[#062f85]'
                                : 'text-[#c1c9d5]',
                            href === '#' ? 'pointer-events-none' : '',
                        ].join(' ')}
                    >
                        <Icon size={17} strokeWidth={1.8} />
                        <span className="max-w-full truncate">
                            {item.label}
                        </span>
                        {active && (
                            <span className="absolute bottom-0 h-[3px] w-7 rounded-t-full bg-[#062f85]" />
                        )}
                    </Link>
                );
            })}
        </nav>
    );
}
