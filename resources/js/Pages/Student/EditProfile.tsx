import '../../../css/student-profile.css';

import ContactInformationForm from '@/Features/Student/Profile/ContactInformationForm';
import DisplayLanguageCard from '@/Features/Student/Profile/DisplayLanguageCard';
import ProfilePhotoEditor from '@/Features/Student/Profile/ProfilePhotoEditor';
import {
    defaultStudentProfile,
    readDemoStudentProfile,
    saveDemoStudentProfile,
} from '@/Features/Student/Profile/profileDemoStorage';
import StudentLayout from '@/Layouts/StudentLayout';
import type { StudentProfileData } from '@/types/student-profile';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

type OptionalAuthProps = {
    auth?: {
        user?: {
            name?: string;
            email?: string;
        } | null;
    };
};

export default function EditProfile() {
    const page = usePage();
    const props = page.props as OptionalAuthProps;
    const demoMode = page.url.startsWith('/demo');

    const [profile, setProfile] =
        useState<StudentProfileData>(defaultStudentProfile);

    useEffect(() => {
        const stored = readDemoStudentProfile();

        setProfile({
            ...stored,
            fullName:
                !demoMode && props.auth?.user?.name
                    ? props.auth.user.name
                    : stored.fullName,
            email:
                !demoMode && props.auth?.user?.email
                    ? props.auth.user.email
                    : stored.email,
        });
    }, [demoMode, props.auth?.user?.email, props.auth?.user?.name]);

    const profileHref = demoMode
        ? '/demo/profile'
        : '/student/profile';

    const patchProfile = (
        patch: Partial<StudentProfileData>,
    ) => {
        setProfile((current) => ({
            ...current,
            ...patch,
        }));
    };

    const save = (event: FormEvent) => {
        event.preventDefault();

        // Frontend implementation: persist all UI fields locally so the
        // demo works across Profile -> Edit Profile -> Profile.
        // The current backend ProfileController only covers its existing
        // account fields; phone/address/photo need backend model/API work
        // before they should be submitted to production persistence.
        saveDemoStudentProfile(profile);
        router.visit(profileHref);
    };

    return (
        <StudentLayout
            studentName={profile.fullName}
            studentId={profile.studentId}
            centerName={profile.centerName}
            branchName={profile.branchName}
            pageTitle="Edit Profile"
            activeNav="profile"
        >
            <Head title="Edit Profile" />

            <form
                className="student-profile-page profile-stack"
                onSubmit={save}
            >
                <Link
                    href={profileHref}
                    className="edit-profile-back"
                >
                    <ArrowLeft size={13} />
                    Back to Profile
                </Link>

                <div className="edit-profile-top-grid">
                    <ProfilePhotoEditor
                        name={profile.fullName}
                        photoDataUrl={profile.photoDataUrl}
                        onPhotoChange={(photoDataUrl) =>
                            patchProfile({ photoDataUrl })
                        }
                    />

                    <DisplayLanguageCard
                        value={profile.displayLanguage}
                        onChange={(displayLanguage) =>
                            patchProfile({ displayLanguage })
                        }
                    />
                </div>

                <ContactInformationForm
                    profile={profile}
                    onChange={patchProfile}
                />

                <div className="edit-page-actions">
                    <button
                        type="submit"
                        className="edit-save"
                    >
                        <Save size={15} />
                        Save Changes
                    </button>

                    <Link
                        href={profileHref}
                        className="edit-cancel"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </StudentLayout>
    );
}
