import { Building2, MapPin } from 'lucide-react';

export default function WelcomeBanner({
    dateLabel,
    title,
    centerName,
    branchName,
}: {
    dateLabel: string;
    title: string;
    centerName: string;
    branchName: string;
}) {
    return (
        <section className="relative overflow-hidden rounded-[17px] bg-[#062f85] px-6 py-6 text-white sm:px-7 lg:min-h-[130px]">
            <div className="relative z-10">
                <p className="text-[11px] font-medium text-[#c8d5f3] sm:text-[12px]">
                    {dateLabel}
                </p>
                <h2 className="mt-2 text-[20px] font-extrabold leading-tight sm:text-[21px]">
                    {title}
                </h2>

                <div className="mt-3 flex flex-col gap-2 text-[11px] text-[#c8d5f3] sm:flex-row sm:items-center sm:gap-5">
                    <span className="flex items-center gap-2">
                        <Building2 size={14} strokeWidth={1.7} />
                        {centerName}
                    </span>
                    <span className="flex items-center gap-2">
                        <MapPin size={14} strokeWidth={1.7} />
                        {branchName}
                    </span>
                </div>
            </div>

            <span className="absolute -right-7 -top-10 h-28 w-28 rounded-full bg-white/10" />
            <span className="absolute -bottom-12 right-8 h-24 w-24 rounded-full bg-white/[0.07]" />
        </section>
    );
}
