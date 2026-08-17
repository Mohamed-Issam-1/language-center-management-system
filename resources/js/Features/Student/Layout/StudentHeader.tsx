import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import { ChevronDown, Globe2 } from 'lucide-react';

function initials(name: string) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export default function StudentHeader({
    studentName,
    pageTitle,
}: {
    studentName: string;
    pageTitle: string;
}) {
    return (
        <header className="fixed left-0 right-0 top-0 z-20 h-[60px] border-b border-[#e8ebf2] bg-white lg:left-[240px]">
            <div className="flex h-full items-center justify-between px-5 sm:px-7 lg:px-8">
                <div className="flex min-w-0 items-center gap-4 lg:hidden">
                    <ApplicationLogo className="h-9 w-auto shrink-0" />
                    <h1 className="truncate text-[18px] font-extrabold text-[#062f85]">
                        {pageTitle}
                    </h1>
                </div>

                <h1 className="hidden text-[22px] font-extrabold text-[#062f85] lg:block">
                    {pageTitle}
                </h1>

                <div className="hidden items-center gap-3 sm:flex">
                    <div className="group relative">
                        <button
                            type="button"
                            aria-label="Language"
                            className="flex h-8 items-center gap-2 rounded-lg border border-[#dfe4ec] bg-white px-3 text-[12px] font-semibold text-[#6f7888]"
                        >
                            <Globe2 size={14} />
                            العربية
                        </button>
                        <div className="pointer-events-none absolute right-0 top-10 hidden w-max rounded-md bg-[#1f2937] px-2.5 py-1.5 text-[10px] font-medium text-white shadow-lg group-hover:block">
                            Multilingual support is not integrated yet.
                        </div>
                    </div>

                    <Dropdown>
                        <Dropdown.Trigger>
                            <button
                                type="button"
                                className="flex h-9 items-center gap-2 rounded-lg bg-[#e9eefb] pl-1.5 pr-2.5 text-[#0c327f]"
                            >
                                <span className="grid h-7 w-7 place-items-center rounded-full bg-[#062f85] text-[10px] font-extrabold text-white">
                                    {initials(studentName)}
                                </span>
                                <span className="max-w-[150px] truncate text-[12px] font-bold">
                                    {studentName}
                                </span>
                                <ChevronDown size={13} />
                            </button>
                        </Dropdown.Trigger>

                        <Dropdown.Content>
                            <Dropdown.Link href={route('profile.edit')}>
                                Profile
                            </Dropdown.Link>
                            <Dropdown.Link
                                href={route('logout')}
                                method="post"
                                as="button"
                            >
                                Log Out
                            </Dropdown.Link>
                        </Dropdown.Content>
                    </Dropdown>
                </div>

                <div className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[#062f85] text-[10px] font-extrabold text-white sm:hidden">
                    {initials(studentName)}
                </div>
            </div>
        </header>
    );
}
