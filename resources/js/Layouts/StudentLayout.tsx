import StudentBottomNav from '@/Features/Student/Layout/StudentBottomNav';
import StudentHeader from '@/Features/Student/Layout/StudentHeader';
import StudentSidebar from '@/Features/Student/Layout/StudentSidebar';
import type { PropsWithChildren } from 'react';

type StudentLayoutProps = PropsWithChildren<{
    studentName: string;
    studentId: string;
    centerName: string;
    branchName: string;
}>;

export default function StudentLayout({
    studentName,
    studentId,
    centerName,
    branchName,
    children,
}: StudentLayoutProps) {
    return (
        <div className="min-h-screen bg-[#f6f8fc] text-[#202631]">
            <StudentSidebar
                studentName={studentName}
                studentId={studentId}
                centerName={centerName}
                branchName={branchName}
            />

            <StudentHeader studentName={studentName} />

            <main className="min-h-screen pt-[60px] lg:pl-[240px]">
                <div className="mx-auto w-full max-w-[1180px] px-4 pb-[86px] pt-4 sm:px-6 lg:px-8 lg:pb-8 lg:pt-7">
                    {children}
                </div>
            </main>

            <StudentBottomNav />
        </div>
    );
}
