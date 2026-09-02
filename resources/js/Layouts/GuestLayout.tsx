import { PropsWithChildren } from 'react';

import ApplicationLogo from '@/Components/ApplicationLogo';
import AuthBrandPanel from '@/Components/Auth/AuthBrandPanel';
import LanguageToggle from '@/Components/Auth/LanguageToggle';

type GuestLayoutProps = PropsWithChildren<{
    mobileTitle?: string;
    mobileSubtitle?: string;
}>;

export default function GuestLayout({
    children,
    mobileTitle = 'Welcome Back',
    mobileSubtitle = 'Sign in to access your dashboard.',
}: GuestLayoutProps) {
    return (
        <div className="auth-guest-shell min-h-screen bg-[#f2f4fc] lg:grid lg:grid-cols-[42%_58%]">
            {/* Mobile header */}
            <header className="relative h-[171px] overflow-hidden bg-[#2d3eb3] px-5 pt-6 text-white lg:hidden">
                {/* Decorations */}
                <div className="pointer-events-none absolute -right-[65px] -top-[80px] h-[190px] w-[190px] rounded-full bg-white/[0.08]" />

                <div className="pointer-events-none absolute -left-[65px] bottom-[-75px] h-[180px] w-[180px] rounded-full bg-white/[0.08]" />

                <div className="relative z-10 flex items-center justify-between">
                    <ApplicationLogo
                        variant="white"
                        className="h-auto w-[140px]"
                    />

                    <LanguageToggle variant="dark" />
                </div>

                <div className="relative z-10 mt-7">
                    <h2 className="text-[20px] font-bold">
                        {mobileTitle}
                    </h2>

                    {mobileSubtitle && (
                        <p className="mt-1 max-w-[330px] text-[13px] leading-[19px] text-white/70">
                            {mobileSubtitle}
                        </p>
                    )}
                </div>
            </header>

            {/* Left branding panel */}
            <AuthBrandPanel />

            {/* Right authentication content */}
            <main className="auth-guest-main flex min-h-[calc(100vh-171px)] items-start justify-center px-3 lg:min-h-screen lg:items-center lg:px-16 lg:py-10">
                <div className="flex w-full max-w-[460px] flex-col rounded-b-[18px] bg-white px-[18px] pb-6 shadow-[0_8px_25px_rgba(39,54,130,0.08)] lg:rounded-none lg:bg-transparent lg:px-0 lg:pb-0 lg:shadow-none">
                    {children}
                </div>
            </main>
        </div>
    );
}