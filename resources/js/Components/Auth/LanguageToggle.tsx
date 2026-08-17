interface LanguageToggleProps {
    variant?: 'light' | 'dark';
}

export default function LanguageToggle({
    variant = 'dark',
}: LanguageToggleProps) {
    const isDark = variant === 'dark';

    const handleClick = () => {
        // Multilingual support is not integrated yet.
    };

    return (
        <div className="group relative">
            <div
                className={
                    isDark
                        ? 'flex h-[38px] items-center rounded-[9px] bg-white/[0.12] p-[3px]'
                        : 'flex h-[38px] items-center rounded-[9px] border border-[#e2e5ed] bg-white p-[3px]'
                }
            >
                <button
                    type="button"
                    onClick={handleClick}
                    className={
                        isDark
                            ? 'flex h-[32px] min-w-[42px] items-center justify-center rounded-[6px] bg-white px-2 text-[13px] font-bold text-[#3544bd]'
                            : 'flex h-[32px] min-w-[42px] items-center justify-center rounded-[6px] bg-[#3f46d3] px-2 text-[13px] font-bold text-white'
                    }
                >
                    EN
                </button>

                <button
                    type="button"
                    onClick={handleClick}
                    className={
                        isDark
                            ? 'flex h-[32px] min-w-[42px] items-center justify-center rounded-[6px] px-2 text-[13px] font-bold text-white/70 transition hover:text-white'
                            : 'flex h-[32px] min-w-[42px] items-center justify-center rounded-[6px] px-2 text-[13px] font-bold text-[#a4a9b4] transition hover:text-[#747a86]'
                    }
                >
                    AR
                </button>
            </div>

            {/* Tooltip */}
            <div
                role="tooltip"
                className="
                    pointer-events-none absolute right-0 top-[calc(100%+10px)]
                    z-50 w-max max-w-[240px]
                    translate-y-1 rounded-[8px]
                    bg-[#22252d] px-3 py-2
                    text-[12px] font-medium leading-[18px] text-white
                    opacity-0 shadow-lg
                    transition-all duration-150
                    group-hover:translate-y-0 group-hover:opacity-100
                    group-focus-within:translate-y-0 group-focus-within:opacity-100
                "
            >
                Multilingual support is not integrated yet.

                <div className="absolute -top-1.5 right-[32px] h-3 w-3 rotate-45 bg-[#22252d]" />
            </div>
        </div>
    );
}