import ProfileAvatar from './ProfileAvatar';
import { Link } from '@inertiajs/react';
import { SquarePen } from 'lucide-react';
import type { StudentProfileData } from '@/types/student-profile';

export default function ProfileHero({
    profile,
    editHref,
}: {
    profile: StudentProfileData;
    editHref: string;
}) {
    return (
        <section className="profile-card profile-hero">
            <div className="profile-identity">
                <ProfileAvatar
                    name={profile.fullName}
                    photoDataUrl={profile.photoDataUrl}
                    showStatus
                />

                <div className="min-w-0">
                    <h2 className="profile-name">
                        {profile.fullName}
                    </h2>
                    <p className="profile-role-line">
                        {profile.studentId} · {profile.roleLabel}
                    </p>

                    <div className="profile-badges">
                        <span className="profile-badge center">
                            {profile.centerName}
                        </span>
                        <span className="profile-badge branch">
                            {profile.branchName}
                        </span>
                    </div>
                </div>
            </div>

            <Link
                href={editHref}
                className="profile-primary-action"
            >
                <SquarePen size={14} />
                Edit Profile
            </Link>
        </section>
    );
}
