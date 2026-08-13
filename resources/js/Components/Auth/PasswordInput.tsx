import { InputHTMLAttributes, useState } from 'react';

import TextInput from '@/Components/TextInput';

export default function PasswordInput(
    props: InputHTMLAttributes<HTMLInputElement>,
) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <TextInput
                {...props}
                type={visible ? 'text' : 'password'}
                className={`pr-12 ${props.className ?? ''}`}
            />

            <button
                type="button"
                onClick={() => setVisible(!visible)}
                className="absolute right-4 top-1/2 flex -translate-y-1/2 items-center justify-center text-[#bbc0ca] transition hover:text-[#6d7380]"
                aria-label={
                    visible
                        ? 'Hide password'
                        : 'Show password'
                }
            >
                {visible ? <EyeOff /> : <Eye />}
            </button>
        </div>
    );
}

function Eye() {
    return (
        <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
            <circle cx="12" cy="12" r="2.5" />
        </svg>
    );
}

function EyeOff() {
    return (
        <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M3 3l18 18" />
            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
            <path d="M9.8 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a17 17 0 0 1-2.1 3.2" />
            <path d="M6.2 6.2C3.6 8 2 12 2 12s3.5 8 10 8a10 10 0 0 0 4.1-.9" />
        </svg>
    );
}