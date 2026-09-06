import { TriangleAlert } from 'lucide-react';

export default function AttendanceWarning({
    warningText,
}: {
    warningText: string | null;
}) {
    if (!warningText) {
        return null;
    }

    return (
        <section className="flex gap-4 rounded-[14px] border border-[#ff8c91] bg-[#ffe2e2] px-5 py-4 text-[#e62e36]">
            <TriangleAlert
                className="mt-1 shrink-0"
                size={20}
            />

            <div>
                <h3 className="text-[12px] font-extrabold">
                    Absence Limit Warning
                </h3>

                <p className="mt-2 text-[11px] leading-5">
                    {warningText}
                </p>
            </div>
        </section>
    );
}