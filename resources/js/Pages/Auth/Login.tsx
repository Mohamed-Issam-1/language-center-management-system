import {
    FormEventHandler,
    useState,
} from 'react';

import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import PasswordInput from '@/Components/Auth/PasswordInput';
import ApplicationLogo from '@/Components/ApplicationLogo';

import GuestLayout from '@/Layouts/GuestLayout';

import {
    Head,
    Link,
    useForm,
} from '@inertiajs/react';

export default function Login({
    status,
    canResetPassword = true,
}: {
    status?: string;
    canResetPassword?: boolean;
}) {
    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
    } = useForm({
        login_identifier: '',
        password: '',
        remember: false,
    });

    const [clientError, setClientError] = useState<
        string | null
    >(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        setClientError(null);

        if (!data.login_identifier.trim()) {
            setClientError(
                'Please enter your login ID.',
            );
            return;
        }

        if (!data.password) {
            setClientError(
                'Please enter your password.',
            );
            return;
        }

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Sign In" />

            {/* Desktop logo */}
            <div className="mb-8 hidden lg:block">
                <ApplicationLogo className="h-auto w-[205px] text-[#073e95]" />
            </div>

            <div className="mb-8">
                <h1 className="text-[30px] font-bold tracking-[-0.035em] text-[#22252d]">
                    Sign In
                </h1>

                <p className="mt-1.5 text-[14px] text-[#adb2be]">
                    Enter your credentials to access your
                    account.
                </p>
            </div>

            {status && (
                <div className="mb-5 rounded-[10px] border border-[#a4dfbc] bg-[#f0fbf4] px-4 py-3 text-sm text-[#17984c]">
                    {status}
                </div>
            )}

            {(clientError ||
                errors.login_identifier) && (
                <div className="mb-5 flex min-h-[46px] items-center gap-2.5 rounded-[10px] border border-[#ff9d9d] bg-[#fff0f0] px-4 text-[13px] text-[#ef3434]">
                    <WarningIcon />

                    <span>
                        {clientError ||
                            errors.login_identifier}
                    </span>
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <InputLabel
                        htmlFor="login_identifier"
                        value="Login ID"
                    />

                    <TextInput
                        id="login_identifier"
                        type="text"
                        name="login_identifier"
                        value={data.login_identifier}
                        placeholder="Enter your login ID"
                        autoComplete="username"
                        isFocused
                        onChange={(e) =>
                            setData(
                                'login_identifier',
                                e.target.value,
                            )
                        }
                    />

                    {data.login_identifier && (
                        <InputError
                            message={
                                errors.login_identifier
                            }
                        />
                    )}
                </div>

                <div className="mt-[18px]">
                    <InputLabel
                        htmlFor="password"
                        value="Password"
                    />

                    <PasswordInput
                        id="password"
                        name="password"
                        value={data.password}
                        placeholder="Enter your password"
                        autoComplete="current-password"
                        onChange={(e) =>
                            setData(
                                'password',
                                e.target.value,
                            )
                        }
                    />

                    <InputError
                        message={errors.password}
                    />
                </div>

                <div className="mt-[13px] flex items-center justify-between">
                    <label className="flex cursor-pointer items-center gap-2">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) =>
                                setData(
                                    'remember',
                                    e.target.checked,
                                )
                            }
                        />

                        <span className="text-[13px] text-[#858b97]">
                            Remember me
                        </span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route(
                                'password.request',
                            )}
                            className="text-[13px] font-semibold text-[#3842c9] transition hover:text-[#252fac]"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <div className="mt-6">
                    <PrimaryButton
                        disabled={processing}
                    >
                        {processing ? (
                            <>
                                <Spinner />
                                Signing in...
                            </>
                        ) : (
                            <>
                                Sign In
                                <ArrowRight />
                            </>
                        )}
                    </PrimaryButton>
                </div>

                <p className="mt-3 text-center text-[13px] text-[#adb2bd]">
                    New student?{' '}

                    <Link
                        href={route('register')}
                        className="font-semibold text-[#3842c9]"
                    >
                        Register
                    </Link>
                </p>
            </form>

            <footer className="mt-9 border-t border-[#e7eaf1] pt-6 text-[12px] text-[#c2c6cf]">
                © 2026 LCMS · Taqat University
            </footer>
        </GuestLayout>
    );
}

function ArrowRight() {
    return (
        <svg
            className="ml-2"
            width="17"
            height="17"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
        >
            <path d="M5 12h14M14 7l5 5-5 5" />
        </svg>
    );
}

function Spinner() {
    return (
        <span className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
    );
}

function WarningIcon() {
    return (
        <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
        >
            <path d="M12 3 2.7 20h18.6L12 3Z" />
            <path d="M12 9v4" />
            <circle
                cx="12"
                cy="17"
                r=".7"
                fill="currentColor"
            />
        </svg>
    );
}