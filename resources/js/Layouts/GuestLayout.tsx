import { PropsWithChildren } from 'react';

import ApplicationLogo from '@/Components/ApplicationLogo';
import AuthBrandPanel from '@/Components/Auth/AuthBrandPanel';

export default function GuestLayout({
    children,
}: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-[#f2f4fc] lg:grid lg:grid-cols-[42%_58%]">
            {/* Left branding panel */}
            <AuthBrandPanel />

            {/* Right authentication content */}
            <main className="flex min-h-screen justify-center px-6 py-10 sm:px-10 lg:px-16">
                <div className="flex w-full max-w-[460px] flex-col">
                    {/* Mobile logo */}
                    <div className="mb-10 flex justify-center lg:hidden">
                        <ApplicationLogo
                            variant="blue"
                            className="h-auto w-[205px]"
                        />
                    </div>

                    {children}
                </div>
            </main>
        </div>
    );
}