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
                `mb-2 block text-[11px] font-bold uppercase ` +
                `tracking-[0.035em] text-[#6c7381] ` +
                className
            }
        >
            {value ? value : children}
        </label>
    );
}