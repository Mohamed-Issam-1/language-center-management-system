import {
    CircleCheck,
    KeyRound,
} from 'lucide-react';
import {
    router,
    usePage,
} from '@inertiajs/react';
import {
    FormEvent,
    useState,
} from 'react';

export default function AccountSecurityCard() {
    const page =
        usePage();

    const demoMode =
        page.url.startsWith(
            '/demo',
        );

    const [
        expanded,
        setExpanded,
    ] =
        useState(false);

    const [
        currentPassword,
        setCurrentPassword,
    ] =
        useState('');

    const [
        newPassword,
        setNewPassword,
    ] =
        useState('');

    const [
        error,
        setError,
    ] =
        useState('');

    const [
        success,
        setSuccess,
    ] =
        useState(false);

    const [
        processing,
        setProcessing,
    ] =
        useState(false);

    const reset = () => {
        setCurrentPassword('');
        setNewPassword('');
        setError('');
        setExpanded(false);
    };

    const open = () => {
        setSuccess(false);
        setError('');
        setExpanded(true);
    };

    const submit = (
        event: FormEvent,
    ) => {
        event.preventDefault();

        if (
            !currentPassword ||
            !newPassword
        ) {
            setError(
                'Enter both password fields.',
            );

            return;
        }

        if (demoMode) {
            setCurrentPassword(
                '',
            );

            setNewPassword(
                '',
            );

            setError('');
            setExpanded(false);
            setSuccess(true);

            return;
        }

        setProcessing(true);
        setError('');

        router.put(
            '/password',
            {
                current_password:
                    currentPassword,

                password:
                    newPassword,

                password_confirmation:
                    newPassword,
            },
            {
                preserveScroll:
                    true,

                onSuccess: () => {
                    setCurrentPassword(
                        '',
                    );

                    setNewPassword(
                        '',
                    );

                    setExpanded(
                        false,
                    );

                    setSuccess(
                        true,
                    );
                },

                onError: () => {
                    setError(
                        'Password could not be updated. Check your current password and password requirements.',
                    );
                },

                onFinish: () =>
                    setProcessing(
                        false,
                    ),
            },
        );
    };

    return (
        <section className="profile-card profile-security-card">
            <h2 className="profile-section-title">
                Account Security
            </h2>

            {success ? (
                <div className="mt-4 flex min-h-[44px] items-center gap-2 rounded-[9px] bg-[#ddf6fa] px-4 text-[12px] font-semibold text-[#00a6bd]">
                    <CircleCheck
                        size={18}
                    />

                    Password changed
                    successfully!
                </div>
            ) : expanded ? (
                <form
                    className="profile-security-form is-expanded"
                    onSubmit={
                        submit
                    }
                >
                    <div className="profile-security-inputs">
                        <input
                            type="password"
                            value={
                                currentPassword
                            }
                            onChange={(
                                event,
                            ) =>
                                setCurrentPassword(
                                    event
                                        .target
                                        .value,
                                )
                            }
                            className="profile-field-input"
                            placeholder="Current password"
                            autoComplete="current-password"
                        />

                        <input
                            type="password"
                            value={
                                newPassword
                            }
                            onChange={(
                                event,
                            ) =>
                                setNewPassword(
                                    event
                                        .target
                                        .value,
                                )
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
                            disabled={
                                processing
                            }
                        >
                            {processing
                                ? 'Saving...'
                                : 'Save'}
                        </button>

                        <button
                            type="button"
                            className="profile-cancel-button"
                            onClick={
                                reset
                            }
                        >
                            Cancel
                        </button>
                    </div>

                    {error && (
                        <p className="profile-form-message">
                            {error}
                        </p>
                    )}
                </form>
            ) : (
                <div className="profile-security-collapsed">
                    <button
                        type="button"
                        className="profile-change-password-button"
                        onClick={
                            open
                        }
                    >
                        <KeyRound
                            size={
                                15
                            }
                        />

                        Change
                        Password
                    </button>
                </div>
            )}
        </section>
    );
}