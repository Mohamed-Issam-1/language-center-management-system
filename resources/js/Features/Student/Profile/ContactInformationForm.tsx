import { Info } from 'lucide-react';
import type { StudentProfileData } from '@/types/student-profile';

export default function ContactInformationForm({
    profile,
    onChange,
}: {
    profile: StudentProfileData;
    onChange: (patch: Partial<StudentProfileData>) => void;
}) {
    return (
        <section className="profile-card edit-contact-card">
            <h2 className="profile-section-title">
                Contact Information
            </h2>
            <p className="edit-contact-description">
                You can update your phone number and address.
            </p>

            <div className="edit-fields-grid">
                <label className="edit-field">
                    <span className="edit-field-label">
                        Full Name (Read-only)
                    </span>
                    <input
                        className="edit-field-input"
                        value={profile.fullName}
                        readOnly
                    />
                </label>

                <label className="edit-field">
                    <span className="edit-field-label">
                        Student ID (Read-only)
                    </span>
                    <input
                        className="edit-field-input"
                        value={profile.studentId}
                        readOnly
                    />
                </label>

                <label className="edit-field">
                    <span className="edit-field-label">
                        Email Address
                    </span>
                    <input
                        type="email"
                        className="edit-field-input"
                        value={profile.email}
                        onChange={(event) =>
                            onChange({
                                email: event.target.value,
                            })
                        }
                    />
                </label>

                <label className="edit-field">
                    <span className="edit-field-label">
                        Phone Number
                    </span>
                    <input
                        type="tel"
                        className="edit-field-input"
                        value={profile.phone}
                        onChange={(event) =>
                            onChange({
                                phone: event.target.value,
                            })
                        }
                    />
                </label>

                <label className="edit-field full">
                    <span className="edit-field-label">
                        Address
                    </span>
                    <input
                        className="edit-field-input"
                        value={profile.address}
                        onChange={(event) =>
                            onChange({
                                address: event.target.value,
                            })
                        }
                    />
                </label>
            </div>

            <p className="edit-readonly-note">
                <Info size={13} />
                To update your name or student ID, contact the
                branch administration.
            </p>
        </section>
    );
}
