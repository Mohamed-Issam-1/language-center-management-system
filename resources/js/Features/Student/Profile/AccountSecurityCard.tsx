import { KeyRound } from 'lucide-react';
import { router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

export default function AccountSecurityCard() {
    const page = usePage();
    const demoMode = page.url.startsWith('/demo');

    const [expanded, setExpanded] = useState(false);
    const [currentPassword, setCurrentPassword] =
        useState('');
    const [newPassword, setNewPassword] = useState('');
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);

    const reset = () => {
        setCurrentPassword('');
        setNewPassword('');
        setMessage('');
        setExpanded(false);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!currentPassword || !newPassword) {
            setMessage('Enter both password fields.');
            return;
        }

        if (demoMode) {
            setMessage('Password change simulated for demo.');
            setCurrentPassword('');
            setNewPassword('');
            return;
        }

        setProcessing(true);
        setMessage('');

        router.put(
            '/password',
            {
                current_password: currentPassword,
                password: newPassword,
                // The current backend requires confirmation, while the
                // approved UI contains only current + new password fields.
                password_confirmation: newPassword,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCurrentPassword('');
                    setNewPassword('');
                    setMessage('Password updated.');
                },
                onError: () => {
                    setMessage(
                        'Password could not be updated. Check your current password and password requirements.',
                    );
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <section
            className={[
                'profile-card profile-security-card',
                !expanded ? 'is-collapsed' : '',
            ].join(' ')}
        >
            <h2 className="profile-section-title">
                Account Security
            </h2>

            <div className="profile-security-collapsed">
                <button
                    type="button"
                    className="profile-change-password-button"
                    onClick={() => setExpanded(true)}
                >
                    <KeyRound size={15} />
                    Change Password
                </button>
            </div>

            <form
                className={[
                    'profile-security-form',
                    expanded ? 'is-expanded' : '',
                ].join(' ')}
                onSubmit={submit}
            >
                <div className="profile-security-inputs">
                    <input
                        type="password"
                        value={currentPassword}
                        onChange={(event) =>
                            setCurrentPassword(event.target.value)
                        }
                        className="profile-field-input"
                        placeholder="Current password"
                        autoComplete="current-password"
                    />

                    <input
                        type="password"
                        value={newPassword}
                        onChange={(event) =>
                            setNewPassword(event.target.value)
                        }
                        className="profile-field-input"
                        placeholder="New password"
                        autoComplete="new-password"
                    />
                </div>

                <div className="profile-security-actions">
                    <button
                        type="submit"
                        className="profile-save-button"
                        disabled={processing}
                    >
                        {processing ? 'Saving...' : 'Save'}
                    </button>

                    <button
                        type="button"
                        className="profile-cancel-button"
                        onClick={reset}
                    >
                        Cancel
                    </button>
                </div>

                {message && (
                    <p className="profile-form-message">
                        {message}
                    </p>
                )}
            </form>
        </section>
    );
}
