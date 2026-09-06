import {
    CalendarDays,
    IdCard,
    Mail,
    MapPin,
    Phone,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { StudentProfileData } from '@/types/student-profile';

type Item = {
    label: string;
    value: string;
    icon: LucideIcon;
};

export default function PersonalInformationCard({
    profile,
}: {
    profile: StudentProfileData;
}) {
    const items: Item[] = [
        {
            label: 'Full Name',
            value: profile.fullName,
            icon: UserRound,
        },
        {
            label: 'Student ID',
            value: profile.studentId,
            icon: IdCard,
        },
        {
            label: 'Email Address',
            value: profile.email,
            icon: Mail,
        },
        {
            label: 'Phone Number',
            value: profile.phone,
            icon: Phone,
        },
        {
            label: 'Address',
            value: profile.address,
            icon: MapPin,
        },
        {
            label: 'Enrolled On',
            value: profile.enrolledOn,
            icon: CalendarDays,
        },
    ];

    return (
        <section className="profile-card profile-card-pad">
            <h2 className="profile-section-title">
                Personal Information
            </h2>

            <div className="profile-info-list">
                {items.map((item) => {
                    const Icon = item.icon;

                    return (
                        <div
                            key={item.label}
                            className="profile-info-row"
                        >
                            <Icon
                                size={15}
                                className="profile-info-icon"
                            />

                            <div>
                                <p className="profile-info-label">
                                    {item.label}
                                </p>
                                <p className="profile-info-value">
                                    {item.value}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
