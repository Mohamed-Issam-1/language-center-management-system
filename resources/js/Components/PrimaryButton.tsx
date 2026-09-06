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
                `inline-flex h-[40px] w-full items-center justify-center lg:h-[48px] ` +
`rounded-[11px] bg-[#3f46d3] px-5 ` +
`text-[13px] font-semibold text-white lg:text-[15px] ` +
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