import ApplicationLogo from '@/Components/ApplicationLogo';
import LanguageToggle from '@/Components/Auth/LanguageToggle';

export default function AuthBrandPanel() {
    return (
        <aside className="relative hidden min-h-screen overflow-hidden bg-[#2d3eb3] text-white lg:flex lg:flex-col">
            {/* Decorations */}
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
            <div className="relative z-20 flex items-center justify-between px-[58px] pt-[42px]">
                <ApplicationLogo
                    variant="white"
                    className="h-auto w-[165px]"
                />

                <LanguageToggle variant="dark" />
            </div>

            {/* Main content */}
            <div className="relative z-10 flex flex-1 flex-col items-center">
                <div className="mt-[63px]">
                    <Illustration />
                </div>

                <div className="mt-[18px] text-center">
                    <h1 className="text-[29px] font-bold leading-[1.28] tracking-[-0.02em]">
                        Language Center
                        <br />
                        Management System
                    </h1>

                    <p className="mx-auto mt-[17px] max-w-[360px] text-[16px] leading-[26px] text-white/70">
                        A unified platform for managing academic,
                        <br />
                        administrative, and financial operations.
                    </p>
                </div>

                <div className="mt-[28px] w-full max-w-[365px] space-y-[13px]">
                    <Feature
                        icon={<BuildingIcon />}
                        text="Multi-branch center management"
                    />

                    <Feature
                        icon={<UserIcon />}
                        text="Student & teacher records"
                    />

                    <Feature
                        icon={<DocumentIcon />}
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
        <div className="flex items-center gap-[13px]">
            <div className="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-[9px] bg-white/[0.14] text-white/85">
                {icon}
            </div>

            <span className="text-[16px] text-white/90">
                {text}
            </span>
        </div>
    );
}

function Illustration() {
    return (
        <div className="relative h-[190px] w-[280px] text-white/20">
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

function BuildingIcon() {
    return (
        <svg
            width="17"
            height="17"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
        >
            <rect x="5" y="3" width="14" height="18" rx="2" />
            <path d="M9 7h2M9 11h2M9 15h2M15 7h1M15 11h1M15 15h1" />
        </svg>
    );
}

function UserIcon() {
    return (
        <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
        >
            <circle cx="12" cy="8" r="3" />
            <path d="M6 20v-2a6 6 0 0 1 12 0v2" />
        </svg>
    );
}

function DocumentIcon() {
    return (
        <svg
            width="17"
            height="17"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
        >
            <path d="M6 3h8l4 4v14H6V3Z" />
            <path d="M14 3v5h5M9 12h6M9 16h6" />
        </svg>
    );
}