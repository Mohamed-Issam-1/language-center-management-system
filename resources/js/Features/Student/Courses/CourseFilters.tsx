import { Search } from 'lucide-react';

export type CourseFilterState = {
    search: string;
    status: string;
    branch: string;
};

export default function CourseFilters({
    value,
    statuses,
    branches,
    onChange,
}: {
    value: CourseFilterState;
    statuses: string[];
    branches: string[];
    onChange: (value: CourseFilterState) => void;
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white p-5 shadow-[0_2px_8px_rgba(16,24,40,0.06)]">
            <div className="grid gap-3 lg:grid-cols-[1fr_112px_92px]">
                <label className="relative block">
                    <span className="sr-only">
                        Search courses or teachers
                    </span>
                    <Search
                        size={15}
                        strokeWidth={1.7}
                        className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#6e7887]"
                    />
                    <input
                        type="search"
                        value={value.search}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                search: event.target.value,
                            })
                        }
                        placeholder="Search courses, teachers..."
                        className="h-[42px] w-full rounded-[10px] border-[#dfe4ec] pl-10 pr-4 text-[13px] text-[#333a45] placeholder:text-[#989faa] focus:border-[#123b94] focus:ring-[#123b94]"
                    />
                </label>

                <label>
                    <span className="sr-only">Enrollment status</span>
                    <select
                        value={value.status}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                status: event.target.value,
                            })
                        }
                        className="h-[42px] w-full rounded-[10px] border-[#dfe4ec] px-4 text-[12px] text-[#505866] focus:border-[#123b94] focus:ring-[#123b94]"
                    >
                        <option value="All">All</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
                </label>

                <label>
                    <span className="sr-only">Branch</span>
                    <select
                        value={value.branch}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                branch: event.target.value,
                            })
                        }
                        className="h-[42px] w-full rounded-[10px] border-[#dfe4ec] px-4 text-[12px] text-[#505866] focus:border-[#123b94] focus:ring-[#123b94]"
                    >
                        <option value="All">All</option>
                        {branches.map((branch) => (
                            <option key={branch} value={branch}>
                                {branch}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
        </section>
    );
}
