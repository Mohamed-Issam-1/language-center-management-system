import '../../../css/student-profile.css';

import AccountSecurityCard from '@/Features/Student/Profile/AccountSecurityCard';
import CenterEnrollmentCard from '@/Features/Student/Profile/CenterEnrollmentCard';
import PersonalInformationCard from '@/Features/Student/Profile/PersonalInformationCard';
import ProfileHero from '@/Features/Student/Profile/ProfileHero';
import {
    defaultStudentProfile,
    readDemoStudentProfile,
    readLiveStudentProfileOverrides,
} from '@/Features/Student/Profile/profileDemoStorage';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type { StudentProfileData } from '@/types/student-profile';
import {
    Head,
    usePage,
} from '@inertiajs/react';
import {
    useEffect,
    useState,
} from 'react';

type StudentProfilePageProps =
    PageProps & {
        studentProfile?: StudentProfileData;
    };

export default function Profile() {
    const page =
        usePage<StudentProfilePageProps>();

    const demoMode =
        page.url.startsWith(
            '/demo',
        );

    const [
        profile,
        setProfile,
    ] =
        useState<StudentProfileData>(
            defaultStudentProfile,
        );

    useEffect(() => {
        if (demoMode) {
            setProfile(
                readDemoStudentProfile(),
            );

            return;
        }

        const backendProfile =
            page.props
                .studentProfile;

        if (!backendProfile) {
            return;
        }

        setProfile({
            ...backendProfile,
            ...readLiveStudentProfileOverrides(),
        });
    }, [
        demoMode,
        page.props
            .studentProfile,
    ]);

    const editHref =
        demoMode
            ? '/demo/profile/edit'
            : '/student/profile/edit';

    return (
        <StudentLayout
            studentName={
                profile.fullName
            }
            studentId={
                profile.studentId
            }
            centerName={
                profile.centerName
            }
            branchName={
                profile.branchName
            }
            pageTitle="My Profile"
            activeNav="profile"
            fluid
        >
            <Head title="My Profile" />

            <div className="student-profile-page profile-stack">
                <ProfileHero
                    profile={
                        profile
                    }
                    editHref={
                        editHref
                    }
                />

                <div className="profile-content-grid">
                    <PersonalInformationCard
                        profile={
                            profile
                        }
                    />

                    <div className="profile-right-stack">
                        <CenterEnrollmentCard
                            profile={
                                profile
                            }
                        />

                        <AccountSecurityCard />
                    </div>
                </div>
            </div>
        </StudentLayout>
    );
}