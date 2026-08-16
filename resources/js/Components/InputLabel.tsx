import { LabelHTMLAttributes } from 'react';

export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}: LabelHTMLAttributes<HTMLLabelElement> & {
    value?: string;
}) {
    return (
        <label
            {...props}
            className={
                `mb-[9px] block text-[14px] font-bold uppercase ` +
                `tracking-[0.025em] text-[#6c7381] ` +
                className
            }
        >
            {value ?? children}
        </label>
    );
}