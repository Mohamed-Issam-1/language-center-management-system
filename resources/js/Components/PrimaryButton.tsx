import {
    ButtonHTMLAttributes,
    PropsWithChildren,
} from 'react';

export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}: PropsWithChildren<
    ButtonHTMLAttributes<HTMLButtonElement>
>) {
    return (
        <button
            {...props}
            disabled={disabled}
            className={
                `inline-flex h-[56px] w-full items-center justify-center ` +
                `rounded-[11px] bg-[#3f46d3] px-5 ` +
                `text-[18px] font-semibold text-white ` +
                `transition duration-200 ` +
                `hover:bg-[#353cc3] ` +
                `focus:outline-none focus:ring-4 focus:ring-[#3f46d3]/15 ` +
                `active:scale-[0.995] ` +
                `disabled:cursor-not-allowed disabled:opacity-60 ` +
                className
            }
        >
            {children}
        </button>
    );
}