import {
    Building2,
    FileText,
    UserRound,
} from 'lucide-react';

import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageToggle from '@/Components/Auth/LanguageToggle';

export default function AuthBrandPanel() {
    return (
        <aside className="auth-brand-panel relative hidden min-h-screen overflow-hidden bg-[#2d3eb3] text-white lg:flex lg:flex-col">
            {/* Background decorations */}
            <div className="pointer-events-none absolute -right-[160px] -top-[135px] h-[425px] w-[425px] rounded-full bg-white/[0.075]" />

            <div className="pointer-events-none absolute -bottom-[220px] -left-[280px] h-[550px] w-[550px] rounded-full bg-white/[0.10]" />

            <div className="pointer-events-none absolute left-[110px] top-[285px] h-[205px] w-[205px] rounded-full bg-white/[0.08]" />

            <div className="pointer-events-none absolute left-[112px] top-[192px] h-[34px] w-[34px] rounded-full bg-white/[0.08]" />

            <div className="pointer-events-none absolute right-[168px] top-[151px] h-[8px] w-[8px] rounded-full bg-white/30" />

            <div className="pointer-events-none absolute right-[146px] top-[170px] h-[5px] w-[5px] rounded-full bg-white/25" />

            <div className="pointer-events-none absolute right-[121px] top-[176px] h-[54px] w-[54px] rounded-full border-[9px] border-white/[0.07]">
                <div className="absolute left-1/2 top-1/2 h-[14px] w-[14px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-white/25" />
            </div>

            {/* Header */}
            <div className="auth-brand-header relative z-20 flex items-center justify-between px-[58px] pt-[42px]">
                <ApplicationLogo
                    variant="white"
                    className="h-auto w-[175px]"
                />

                <LanguageToggle variant="dark" />
            </div>

            {/* Main content */}
            <div className="auth-brand-content relative z-10 flex flex-1 flex-col items-center justify-center pb-[35px]">
                <div>
                    <Illustration />
                </div>

                <div className="mt-[17px] text-center">
                    <h1 className="auth-brand-title text-[32px] lg:text-[35px] font-bold leading-[1.26] tracking-[-0.025em]">
                        Language Center
                        <br />
                        Management System
                    </h1>

                    <p className="auth-brand-copy mx-auto mt-[19px] max-w-[390px] text-[18px] lg:text-[19px] leading-[29px] text-white/70">
                        A unified platform for managing academic,
                        <br />
                        administrative, and financial operations.
                    </p>
                </div>

                <div className="auth-brand-features mt-[30px] w-full max-w-[385px] space-y-[15px]">
                    <Feature
                        icon={
                            <Building2
                                size={21}
                                strokeWidth={1.8}
                            />
                        }
                        text="Multi-branch center management"
                    />

                    <Feature
                        icon={
                            <UserRound
                                size={21}
                                strokeWidth={1.8}
                            />
                        }
                        text="Student & teacher records"
                    />

                    <Feature
                        icon={
                            <FileText
                                size={21}
                                strokeWidth={1.8}
                            />
                        }
                        text="Fees, installments & payments"
                    />
                </div>
            </div>
        </aside>
    );
}

function Feature({
    icon,
    text,
}: {
    icon: React.ReactNode;
    text: string;
}) {
    return (
        <div className="auth-brand-feature flex items-center gap-[14px]">
            <div className="auth-brand-feature-icon flex h-[40px] w-[40px] shrink-0 items-center justify-center rounded-[10px] bg-white/[0.14] text-white/90">
                {icon}
            </div>

            <span className="auth-brand-feature-text text-[18px] lg:text-[19px] text-white/90">
                {text}
            </span>
        </div>
    );
}

function Illustration() {
    return (
        <div className="auth-brand-illustration relative h-[190px] w-[280px] text-white/20">
            <svg
                viewBox="0 0 280 190"
                className="relative z-10 h-full w-full"
                fill="none"
                stroke="currentColor"
            >
                <rect
                    x="70"
                    y="42"
                    width="142"
                    height="88"
                    rx="10"
                    strokeWidth="3"
                />

                <rect
                    x="79"
                    y="51"
                    width="124"
                    height="68"
                    rx="4"
                    fill="currentColor"
                    fillOpacity=".12"
                    stroke="none"
                />

                <path
                    d="M43 139h197"
                    strokeWidth="5"
                    strokeLinecap="round"
                />

                <path
                    d="M111 139h58"
                    strokeWidth="7"
                    strokeLinecap="round"
                />

                <rect
                    x="35"
                    y="102"
                    width="17"
                    height="32"
                    rx="3"
                    fill="currentColor"
                    stroke="none"
                />

                <rect
                    x="55"
                    y="96"
                    width="17"
                    height="38"
                    rx="3"
                    fill="currentColor"
                    stroke="none"
                />

                <path
                    d="M91 67h60"
                    strokeWidth="6"
                    strokeLinecap="round"
                />

                <path
                    d="M91 80h91"
                    strokeWidth="4"
                    strokeLinecap="round"
                />

                <path
                    d="M91 91h70"
                    strokeWidth="4"
                    strokeLinecap="round"
                />

                <rect
                    x="90"
                    y="101"
                    width="43"
                    height="13"
                    rx="3"
                    fill="currentColor"
                    stroke="none"
                />
            </svg>
        </div>
    );
}