import ApplicationLogo from '@/Components/ApplicationLogo';

export default function AuthBrandPanel() {
    return (
        <aside className="relative hidden min-h-screen overflow-hidden bg-[#2839ae] text-white lg:flex lg:flex-col">
            {/* Background decorations */}
            <div className="absolute -right-[150px] -top-[130px] h-[420px] w-[420px] rounded-full bg-white/[0.07]" />

            <div className="absolute -bottom-[210px] -left-[190px] h-[520px] w-[520px] rounded-full bg-white/[0.11]" />

            <div className="absolute left-[110px] top-[195px] h-9 w-9 rounded-full bg-white/[0.09]" />

            <div className="absolute right-[125px] top-[155px] h-2.5 w-2.5 rounded-full bg-white/30" />

            <div className="absolute right-[150px] top-[170px] h-1.5 w-1.5 rounded-full bg-white/25" />

            {/* Header */}
            <div className="relative z-10 flex items-start justify-between px-[58px] pt-[42px]">
                <ApplicationLogo className="h-auto w-[165px] text-white" />

                <div className="rounded-[8px] bg-white/10 p-1">
                    <button
                        type="button"
                        className="rounded-[6px] bg-white px-3 py-1.5 text-xs font-bold text-[#3544bd]"
                    >
                        EN
                    </button>
                </div>
            </div>

            {/* Main branding content */}
            <div className="relative z-10 flex flex-1 flex-col items-center justify-center px-12 pb-10">
                <Illustration />

                <div className="mt-8 text-center">
                    <h1 className="text-[27px] font-bold leading-[1.3]">
                        Language Center
                        <br />
                        Management System
                    </h1>

                    <p className="mx-auto mt-5 max-w-[330px] text-[14px] leading-6 text-white/65">
                        A unified platform for managing academic,
                        administrative, and financial operations.
                    </p>
                </div>

                <div className="mt-8 w-full max-w-[365px] space-y-[14px]">
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
        <div className="flex items-center gap-3.5">
            <div className="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[9px] bg-white/[0.14]">
                {icon}
            </div>

            <span className="text-[14px] text-white/85">
                {text}
            </span>
        </div>
    );
}

function Illustration() {
    return (
        <div className="relative h-[185px] w-[280px] text-white/20">
            <div className="absolute left-[28px] top-[60px] h-[125px] w-[125px] rounded-full bg-white/[0.07]" />

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
            width="16"
            height="16"
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
            width="17"
            height="17"
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
            width="16"
            height="16"
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