import {
    forwardRef,
    InputHTMLAttributes,
    useEffect,
    useImperativeHandle,
    useRef,
} from 'react';

type TextInputProps = InputHTMLAttributes<HTMLInputElement> & {
    isFocused?: boolean;
    hasError?: boolean;
};

export default forwardRef<HTMLInputElement, TextInputProps>(
    function TextInput(
        {
            type = 'text',
            className = '',
            isFocused = false,
            hasError = false,
            ...props
        },
        ref,
    ) {
        const localRef = useRef<HTMLInputElement>(null);

        useImperativeHandle(ref, () => localRef.current as HTMLInputElement);

        useEffect(() => {
            if (isFocused) {
                localRef.current?.focus();
            }
        }, [isFocused]);

        return (
            <input
                {...props}
                type={type}
                ref={localRef}
                className={
                    `h-[40px] w-full rounded-[11px] border ` +
                    `px-[16px] text-[13px] text-[#252832] outline-none ` +
                    `placeholder:text-[13px] placeholder:text-[#a9adb6] ` +
                    `transition duration-200 ` +
                    `lg:h-[56px] lg:px-[21px] lg:text-[19px] lg:placeholder:text-[18px] ` +
                    `disabled:cursor-not-allowed disabled:bg-[#f3f4f7] ` +
                    (
                        hasError
                            ? `border-[#ff5656] bg-[#fffafa] focus:border-[#ff5656] focus:ring-2 focus:ring-[#ff5656]/10 `
                            : `border-[#dce0e8] bg-white focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 `
                    ) +
                    className
                }
            />
        );
    },
);