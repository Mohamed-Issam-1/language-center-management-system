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
import LanguageToggle from '@/Components/Auth/LanguageToggle';

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
                'Please enter your email address.',
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

            <div className="flex min-h-full flex-1 flex-col">
                <div className="pt-[48px]">
                    {/* Desktop logo */}
                    <div className="mb-[28px] hidden lg:block">
                        <ApplicationLogo
                            variant="blue"
                            className="h-auto w-[215px]"
                        />
                    </div>

                    {/* Heading */}
                    <div>
                        <h1 className="text-[34px] font-bold leading-tight tracking-[-0.035em] text-[#22252d]">
                            Sign In
                        </h1>

                        <p className="mt-[9px] text-[16px] leading-[24px] text-[#adb2be]">
                            Enter your credentials to access your account.
                        </p>
                    </div>

                    {status && (
                        <div className="mt-6 rounded-[10px] border border-[#a4dfbc] bg-[#f0fbf4] px-4 py-3 text-[14px] text-[#17984c]">
                            {status}
                        </div>
                    )}

                    {(clientError ||
                        errors.login_identifier) && (
                        <div className="mt-6 flex min-h-[48px] items-center gap-2.5 rounded-[10px] border border-[#ff9d9d] bg-[#fff0f0] px-4 text-[14px] text-[#ef3434]">
                            <WarningIcon />

                            <span>
                                {clientError ||
                                    errors.login_identifier}
                            </span>
                        </div>
                    )}

                    {/* No Demo box */}
                    <form
                        onSubmit={submit}
                        className="mt-[30px]"
                    >
                        <div>
                            <InputLabel
                                htmlFor="login_identifier"
                                value="Email Address"
                            />

                            <TextInput
                                id="login_identifier"
                                type="text"
                                name="login_identifier"
                                value={
                                    data.login_identifier
                                }
                                placeholder="name@center.com"
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

                        <div className="mt-[20px]">
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
                                message={
                                    errors.password
                                }
                            />
                        </div>

                        <div className="mt-[14px] flex items-center justify-between">
                            <label className="flex cursor-pointer items-center gap-[9px]">
                                <Checkbox
                                    name="remember"
                                    checked={
                                        data.remember
                                    }
                                    onChange={(e) =>
                                        setData(
                                            'remember',
                                            e.target.checked,
                                        )
                                    }
                                />

                                <span className="text-[15px] text-[#858b97]">
                                    Remember me
                                </span>
                            </label>

                            {canResetPassword && (
                                <Link
                                    href={route(
                                        'password.request',
                                    )}
                                    className="text-[15px] font-semibold text-[#3842c9] transition hover:text-[#252fac]"
                                >
                                    Forgot password?
                                </Link>
                            )}
                        </div>

                        <div className="mt-[26px]">
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

                        <p className="mt-[14px] text-center text-[15px] text-[#adb2bd]">
                            Don't have an account?{' '}
                            <Link
                                href={route(
                                    'register',
                                )}
                                className="font-semibold text-[#3842c9] transition hover:text-[#252fac]"
                            >
                                Create one
                            </Link>
                        </p>
                    </form>
                </div>

                <footer className="mt-[38px] flex items-center justify-between border-t border-[#e6e9f1] pb-[47px] pt-[22px]">
                    <span className="text-[13px] text-[#c2c6cf]">
                        © 2026 LCMS · Taqat University
                    </span>

                    <div className="hidden lg:block">
                        <LanguageToggle variant="light" />
                    </div>
                </footer>
            </div>
        </GuestLayout>
    );
}

function ArrowRight() {
    return (
        <svg
            className="ml-2"
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
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
            width="17"
            height="17"
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