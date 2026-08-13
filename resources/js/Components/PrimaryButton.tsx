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
            className={
                `inline-flex h-[50px] w-full items-center justify-center ` +
                `rounded-[10px] bg-[#3f46d3] px-5 ` +
                `text-[14px] font-semibold text-white ` +
                `transition duration-200 ` +
                `hover:bg-[#343bc1] ` +
                `focus:outline-none focus:ring-4 focus:ring-[#3f46d3]/15 ` +
                `disabled:cursor-not-allowed disabled:opacity-60 ` +
                className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}