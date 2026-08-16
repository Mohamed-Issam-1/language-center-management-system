import {
    forwardRef,
    InputHTMLAttributes,
    useEffect,
    useImperativeHandle,
    useRef,
} from 'react';

export default forwardRef(function TextInput(
    {
        type = 'text',
        className = '',
        isFocused = false,
        ...props
    }: InputHTMLAttributes<HTMLInputElement> & {
        isFocused?: boolean;
    },
    ref,
) {
    const localRef =
        useRef<HTMLInputElement>(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

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
                `h-[52px] w-full rounded-[10px] border border-[#dce0e8] ` +
                `bg-white px-[20px] text-[17px] text-[#252832] outline-none ` +
                `placeholder:text-[16px] placeholder:text-[#a9adb6] ` +
                `transition duration-200 ` +
                `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 ` +
                `disabled:cursor-not-allowed disabled:bg-[#f3f4f7] ` +
                className
            }
        />
    );
});