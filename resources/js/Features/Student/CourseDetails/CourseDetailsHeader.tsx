import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

export default function CourseDetailsHeader({
    title,
    status,
    backHref,
}: {
    title: string;
    status: string;
    backHref: string;
}) {
    return (
        <section className="hidden items-center gap-3 lg:flex">
            <Link
                href={backHref}
                className="inline-flex h-[34px] items-center gap-1 rounded-[9px] bg-[#e8edf8] px-3 text-[12px] font-bold text-[#083686] transition hover:bg-[#dfe6f6]"
            >
                <ArrowLeft size={14} strokeWidth={2} />
                Back
            </Link>

            <h2 className="text-[16px] font-extrabold text-[#252b34]">
                {title}
            </h2>

            <span className="rounded-full bg-[#dcf7fb] px-3 py-1 text-[10px] font-bold text-[#06a8bd]">
                {status}
            </span>
        </section>
    );
}
