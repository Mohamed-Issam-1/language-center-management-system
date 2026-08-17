import {
    ButtonHTMLAttributes,
    PropsWithChildren,
} from 'react';

export default function SecondaryButton({
    type = 'button',
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
            type={type}
            disabled={disabled}
            className={
                `inline-flex h-[40px] w-full items-center justify-center ` +
                `rounded-[11px] border border-[#dce0e8] bg-transparent px-5 ` +
                `text-[12px] font-medium text-[#6f7581] ` +
                `transition duration-200 ` +
                `hover:bg-white ` +
                `focus:outline-none focus:ring-4 focus:ring-[#3f46d3]/10 ` +
                `active:scale-[0.995] ` +
                `disabled:cursor-not-allowed disabled:opacity-60 ` +
                `lg:h-[56px] lg:text-[17px] ` +
                className
            }
        >
            {children}
        </button>
    );
}