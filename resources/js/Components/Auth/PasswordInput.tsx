import { InputHTMLAttributes, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

import TextInput from '@/Components/TextInput';

type PasswordInputProps = InputHTMLAttributes<HTMLInputElement> & {
    hasError?: boolean;
};

export default function PasswordInput({
    className = '',
    ...props
}: PasswordInputProps) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <TextInput
                {...props}
                type={visible ? 'text' : 'password'}
                className={`pr-14 ${className}`}
            />

            <button
                type="button"
                onClick={() => setVisible((current) => !current)}
                className="absolute right-[15px] top-1/2 flex -translate-y-1/2 items-center justify-center text-[#c1c6d0] transition hover:text-[#747a86]"
                aria-label={
                    visible
                        ? 'Hide password'
                        : 'Show password'
                }
            >
                {visible ? (
                    <EyeOff
                        size={18}
                        strokeWidth={1.8}
                    />
                ) : (
                    <Eye
                        size={18}
                        strokeWidth={1.8}
                    />
                )}
            </button>
        </div>
    );
}