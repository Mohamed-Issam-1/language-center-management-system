export default function InputError({
    message,
    className = '',
}: {
    message?: string;
    className?: string;
}) {
    return message ? (
        <p
            className={
                'mt-1.5 text-[12px] text-[#ef4444] ' +
                className
            }
        >
            {message}
        </p>
    ) : null;
}