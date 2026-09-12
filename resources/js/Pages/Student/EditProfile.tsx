import '../../../css/student-profile.css';

import ContactInformationForm from '@/Features/Student/Profile/ContactInformationForm';
import DisplayLanguageCard from '@/Features/Student/Profile/DisplayLanguageCard';
import ProfilePhotoEditor from '@/Features/Student/Profile/ProfilePhotoEditor';
import {
    defaultStudentProfile,
    readDemoStudentProfile,
    readLiveStudentProfileOverrides,
    saveDemoStudentProfile,
    saveLiveStudentProfileOverrides,
} from '@/Features/Student/Profile/profileDemoStorage';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type { StudentProfileData } from '@/types/student-profile';
import {
    Head,
    Link,
    router,
    usePage,
} from '@inertiajs/react';
import {
    ArrowLeft,
    Save,
} from 'lucide-react';
import {
    FormEvent,
    useEffect,
    useState,
} from 'react';

type EditProfilePageProps =
    PageProps & {
        studentProfile?: StudentProfileData;
    };

export default function EditProfile() {
    const page =
        usePage<EditProfilePageProps>();

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

    const profileHref =
        demoMode
            ? '/demo/profile'
            : '/student/profile';

    const patchProfile = (
        patch: Partial<StudentProfileData>,
    ) => {
        setProfile(
            (current) => ({
                ...current,
                ...patch,
            }),
        );
    };

    const save = (
        event: FormEvent,
    ) => {
        event.preventDefault();

        if (demoMode) {
            saveDemoStudentProfile(
                profile,
            );
        } else {
            /*
             * The Student backend currently has no self-service
             * Person update endpoint. Keep these UI-editable
             * values local until the backend team exposes one.
             */
            saveLiveStudentProfileOverrides(
                {
                    email:
                        profile.email,

                    phone:
                        profile.phone,

                    address:
                        profile.address,

                    displayLanguage:
                        profile.displayLanguage,

                    photoDataUrl:
                        profile.photoDataUrl,
                },
            );
        }

        router.visit(
            profileHref,
        );
    };

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
            pageTitle="Edit Profile"
            activeNav="profile"
            fluid
        >
            <Head title="Edit Profile" />

            <form
                className="student-profile-page profile-stack"
                onSubmit={save}
            >
                <Link
                    href={
                        profileHref
                    }
                    className="edit-profile-back"
                >
                    <ArrowLeft
                        size={13}
                    />

                    Back to Profile
                </Link>

                <div className="edit-profile-top-grid">
                    <ProfilePhotoEditor
                        name={
                            profile.fullName
                        }
                        photoDataUrl={
                            profile.photoDataUrl
                        }
                        onPhotoChange={(
                            photoDataUrl,
                        ) =>
                            patchProfile(
                                {
                                    photoDataUrl,
                                },
                            )
                        }
                    />

                    <DisplayLanguageCard
                        value={
                            profile.displayLanguage
                        }
                        onChange={(
                            displayLanguage,
                        ) =>
                            patchProfile(
                                {
                                    displayLanguage,
                                },
                            )
                        }
                    />
                </div>

                <ContactInformationForm
                    profile={
                        profile
                    }
                    onChange={
                        patchProfile
                    }
                />

                <div className="edit-page-actions">
                    <button
                        type="submit"
                        className="edit-save"
                    >
                        <Save
                            size={
                                15
                            }
                        />

                        Save Changes
                    </button>

                    <Link
                        href={
                            profileHref
                        }
                        className="edit-cancel"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </StudentLayout>
    );
}