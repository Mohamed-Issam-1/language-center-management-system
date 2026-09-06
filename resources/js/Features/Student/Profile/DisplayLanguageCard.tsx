import type { StudentProfileData } from '@/types/student-profile';

export default function DisplayLanguageCard({
    value,
    onChange,
}: {
    value: StudentProfileData['displayLanguage'];
    onChange: (
        value: StudentProfileData['displayLanguage'],
    ) => void;
}) {
    return (
        <section className="profile-card edit-language-card">
            <h2 className="profile-section-title">
                Display Language
            </h2>

            <label>
                <span className="edit-language-label" style={{ marginTop: 18 }}>
                    Interface Language
                </span>

                <select
                    className="edit-language-select"
                    value={value}
                    onChange={(event) =>
                        onChange(
                            event.target
                                .value as StudentProfileData['displayLanguage'],
                        )
                    }
                    style={{ marginTop: 0 }}
                >
                    <option value="English">English</option>
                </select>
            </label>
        </section>
    );
}
