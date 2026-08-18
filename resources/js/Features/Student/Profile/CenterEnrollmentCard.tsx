import type { StudentProfileData } from '@/types/student-profile';

export default function CenterEnrollmentCard({
    profile,
}: {
    profile: StudentProfileData;
}) {
    const rows = [
        ['Center', profile.centerName],
        ['Branch', profile.branchName],
        ['Active Courses', profile.activeCourses.toString()],
        ['Display Language', profile.displayLanguage],
    ];

    return (
        <section className="profile-card profile-card-pad">
            <h2 className="profile-section-title">
                Center &amp; Enrollment
            </h2>

            <div className="profile-pairs">
                {rows.map(([label, value]) => (
                    <div key={label} className="profile-pair">
                        <span className="profile-pair-label">
                            {label}
                        </span>
                        <span className="profile-pair-value">
                            {value}
                        </span>
                    </div>
                ))}
            </div>
        </section>
    );
}
