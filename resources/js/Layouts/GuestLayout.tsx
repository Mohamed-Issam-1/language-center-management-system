import { PropsWithChildren } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import AuthBrandPanel from '@/Components/Auth/AuthBrandPanel';

export default function GuestLayout({
    children,
}: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-[#f4f6fd] lg:grid lg:grid-cols-[43%_57%]">
            {/* Left branding panel */}
            <AuthBrandPanel />

            {/* Right content */}
            <main className="flex min-h-screen items-center justify-center px-6 py-10 sm:px-10 lg:px-16">
                <div className="w-full max-w-[460px]">
                    {/* Logo for mobile/tablet */}
                    <div className="mb-10 flex justify-center lg:hidden">
                        <ApplicationLogo className="h-auto w-[185px] text-[#073e95]" />
                    </div>

                    {children}
                </div>
            </main>
        </div>
    );
}