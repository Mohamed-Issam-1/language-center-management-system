export default function CourseTabPlaceholder({
    title,
}: {
    title: string;
}) {
    return (
        <section className="rounded-[16px] border border-[#e7eaf0] bg-white px-6 py-14 text-center shadow-[0_2px_8px_rgba(16,24,40,0.05)]">
            <h3 className="text-[15px] font-extrabold text-[#263142]">
                {title}
            </h3>
            <p className="mx-auto mt-2 max-w-md text-[12px] leading-5 text-[#98a3b2]">
                This tab is connected to the shared course-details navigation.
                Its full content will be implemented from its dedicated UI.
            </p>
        </section>
    );
}
