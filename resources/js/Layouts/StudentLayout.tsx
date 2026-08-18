import StudentBottomNav from '@/Features/Student/Layout/StudentBottomNav';
import StudentHeader from '@/Features/Student/Layout/StudentHeader';
import StudentSidebar from '@/Features/Student/Layout/StudentSidebar';
import type { StudentNavKey } from '@/Features/Student/Layout/StudentSidebar';
import type { CSSProperties, PropsWithChildren } from 'react';

type StudentLayoutProps = PropsWithChildren<{
    studentName: string;
    studentId: string;
    centerName: string;
    branchName: string;
    pageTitle: string;
    activeNav: StudentNavKey;
    mobileBackHref?: string;
    fluid?: boolean;
}>;

export default function StudentLayout({
    studentName,
    studentId,
    centerName,
    branchName,
    pageTitle,
    activeNav,
    mobileBackHref,
    fluid = false,
    children,
}: StudentLayoutProps) {
    const contentStyle: CSSProperties = fluid
        ? {
              width: '100%',
          }
        : {
              width: '100%',
              maxWidth: '1180px',
              marginLeft: 'auto',
              marginRight: 'auto',
          };

    return (
        <div className="min-h-screen bg-[#f6f8fc] text-[#202631]">
            <StudentSidebar
                studentName={studentName}
                studentId={studentId}
                centerName={centerName}
                branchName={branchName}
                activeNav={activeNav}
            />

            <StudentHeader
                studentName={studentName}
                pageTitle={pageTitle}
                mobileBackHref={mobileBackHref}
            />

            <main className="min-h-screen pt-[60px] lg:pl-[240px]">
                <div
                    className="px-4 pb-[86px] pt-4 sm:px-6 lg:px-8 lg:pb-8 lg:pt-7"
                    style={contentStyle}
                >
                    {children}
                </div>
            </main>

            <StudentBottomNav activeNav={activeNav} />
        </div>
    );
}
