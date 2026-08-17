import ApplicationLogo from '@/Components/ApplicationLogo';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PasswordInput from '@/Components/Auth/PasswordInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleCheckBig,
} from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

type Branch = {
    id: string;
    name: string;
};

type Center = {
    id: string;
    name: string;
    branches: Branch[];
};

type RegisterProps = {
    centers?: Center[];
};

/*
|--------------------------------------------------------------------------
| Temporary frontend data
|--------------------------------------------------------------------------
|
| Remove this fallback when the backend starts providing centers and
| branches to the Inertia page.
|
*/
const demoCenters: Center[] = [
    {
        id: '1',
        name: 'Al-Hilal Language Center',
        branches: [
            {
                id: '1',
                name: 'Main Branch',
            },
            {
                id: '2',
                name: 'North Branch',
            },
        ],
    },
    {
        id: '2',
        name: 'Taqat Language Center',
        branches: [
            {
                id: '3',
                name: 'Gaza Branch',
            },
            {
                id: '4',
                name: 'South Branch',
            },
        ],
    },
];

type LocalErrors = {
    name?: string;
    national_id?: string;
    email?: string;
    center_id?: string;
    branch_id?: string;
    password?: string;
    password_confirmation?: string;
    terms?: string;
};

export default function Register({
    centers = [],
}: RegisterProps) {
    const availableCenters =
        centers.length > 0 ? centers : demoCenters;

    const [step, setStep] = useState<1 | 2>(1);
    const [submitted, setSubmitted] = useState(false);
    const [localErrors, setLocalErrors] =
        useState<LocalErrors>({});

    const {
        data,
        setData,
        processing,
    } = useForm({
        name: '',
        national_id: '',
        email: '',
        center_id: '',
        branch_id: '',
        role: 'Student',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const selectedCenter = useMemo(
        () =>
            availableCenters.find(
                (center) => center.id === data.center_id,
            ),
        [availableCenters, data.center_id],
    );

    const passwordChecks = {
        length: data.password.length >= 8,
        uppercase: /[A-Z]/.test(data.password),
        number: /[0-9]/.test(data.password),
    };

    const passwordsMatch =
        data.password.length > 0 &&
        data.password === data.password_confirmation;

    const validateStepOne = () => {
        const nextErrors: LocalErrors = {};

        if (!data.name.trim()) {
            nextErrors.name = 'Full name is required.';
        }

        if (!data.national_id.trim()) {
            nextErrors.national_id =
                'National ID Number is required.';
        }

        if (!data.email.trim()) {
            nextErrors.email = 'Email address is required.';
        } else if (
            !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)
        ) {
            nextErrors.email =
                'Enter a valid email address.';
        }

        if (!data.center_id) {
            nextErrors.center_id =
                'Please select a language center.';
        }

        if (!data.branch_id) {
            nextErrors.branch_id =
                'Please select a branch.';
        }

        setLocalErrors(nextErrors);

        return Object.keys(nextErrors).length === 0;
    };

    const validateStepTwo = () => {
        const nextErrors: LocalErrors = {};

        if (!data.password) {
            nextErrors.password = 'Password is required.';
        } else if (
            !passwordChecks.length ||
            !passwordChecks.uppercase ||
            !passwordChecks.number
        ) {
            nextErrors.password =
                'Password does not meet all requirements.';
        }

        if (!data.password_confirmation) {
            nextErrors.password_confirmation =
                'Please confirm your password.';
        } else if (!passwordsMatch) {
            nextErrors.password_confirmation =
                'Passwords do not match.';
        }

        if (!data.terms) {
            nextErrors.terms =
                'You must agree to the Terms & Conditions and Privacy Policy.';
        }

        setLocalErrors(nextErrors);

        return Object.keys(nextErrors).length === 0;
    };

    const continueToPassword = (
        e: FormEvent<HTMLFormElement>,
    ) => {
        e.preventDefault();

        if (!validateStepOne()) {
            return;
        }

        setLocalErrors({});
        setStep(2);
    };

    const submit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (!validateStepTwo()) {
            return;
        }

        setLocalErrors({});

        /*
        |--------------------------------------------------------------------------
        | BACKEND INTEGRATION LATER
        |--------------------------------------------------------------------------
        |
        | Replace setSubmitted(true) with your final Inertia POST:
        |
        | post(route('register'), {
        |     onSuccess: () => setSubmitted(true),
        | });
        |
        */

        setSubmitted(true);
    };

    if (submitted) {
        return (
            <GuestLayout
                mobileTitle="Account Requested"
                mobileSubtitle=""
            >
                <Head title="Account Requested" />

                <section className="flex flex-col items-center text-center">
                    {/* Desktop logo */}
                    <ApplicationLogo
                        className="mb-10 hidden h-auto w-[185px] lg:block"
                    />

                    {/* Desktop title */}
                    <h1 className="hidden text-[30px] font-bold tracking-[-0.025em] text-[#252832] lg:block">
                        Account Requested
                    </h1>

                    <div className="mt-4 flex h-[82px] w-[82px] items-center justify-center rounded-full border-2 border-[#16b95a] bg-[#ecfff3] text-[#16b95a] lg:mt-8">
                        <CircleCheckBig
                            size={42}
                            strokeWidth={1.8}
                        />
                    </div>

                    <h2 className="mt-5 text-[17px] font-bold text-[#12a84e] lg:text-[21px]">
                        Request Submitted!
                    </h2>

                    <p className="mt-4 max-w-[400px] text-[12px] leading-[20px] text-[#a4a9b4] lg:text-[16px] lg:leading-[26px]">
                        Your registration request has been sent
                        to the center administrator. You will be
                        notified once your account is approved
                        and activated.
                    </p>

                    <p className="mt-3 text-[11px] text-[#b1b5bd] lg:text-[14px]">
                        Request for:{' '}
                        <span className="font-semibold text-[#464b56]">
                            {data.name}
                        </span>{' '}
                        · {data.email}
                    </p>

                    <Link
                        href={route('login')}
                        className="mt-8 flex h-[40px] w-full items-center justify-center gap-2 rounded-[11px] bg-[#3f46d3] px-5 text-[13px] font-semibold text-white transition hover:bg-[#353cc3] focus:outline-none focus:ring-4 focus:ring-[#3f46d3]/15 lg:h-[56px] lg:text-[19px]"
                    >
                        Back to Login
                        <ArrowRight
                            size={17}
                            strokeWidth={2}
                        />
                    </Link>
                </section>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout
            mobileTitle="Create Account"
            mobileSubtitle="Register to request access to the LCMS platform. Your administrator will approve your account."
        >
            <Head title="Create Account" />

            {/* Desktop header */}
            <div className="hidden lg:block">
                <ApplicationLogo
                    className="mb-8 h-auto w-[185px]"
                />

                <h1 className="text-[30px] font-bold tracking-[-0.025em] text-[#252832]">
                    Create Account
                </h1>

                <p className="mt-2 text-[15px] leading-[24px] text-[#adb1ba]">
                    Register to request access to the LCMS
                    platform. Your administrator will approve
                    your account.
                </p>
            </div>

            <RegistrationStepper step={step} />

            {step === 1 ? (
                <form
                    onSubmit={continueToPassword}
                    noValidate
                >
                    <div className="space-y-[15px] lg:space-y-[20px]">
                        <div>
                            <InputLabel
                                htmlFor="name"
                                value="Full Name"
                            />

                            <TextInput
                                id="name"
                                name="name"
                                value={data.name}
                                placeholder="e.g. Mohammad Znaid"
                                autoComplete="name"
                                isFocused
                                hasError={Boolean(
                                    localErrors.name,
                                )}
                                onChange={(e) => {
                                    setData(
                                        'name',
                                        e.target.value,
                                    );

                                    setLocalErrors((current) => ({
                                        ...current,
                                        name: undefined,
                                    }));
                                }}
                            />

                            <InputError
                                message={localErrors.name}
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="national_id"
                                value="National ID Number"
                            />

                            <TextInput
                                id="national_id"
                                name="national_id"
                                value={data.national_id}
                                placeholder="Enter your National ID Number"
                                inputMode="numeric"
                                autoComplete="off"
                                hasError={Boolean(
                                    localErrors.national_id,
                                )}
                                onChange={(e) => {
                                    setData(
                                        'national_id',
                                        e.target.value,
                                    );

                                    setLocalErrors((current) => ({
                                        ...current,
                                        national_id: undefined,
                                    }));
                                }}
                            />

                            <InputError
                                message={
                                    localErrors.national_id
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="email"
                                value="Email Address"
                            />

                            <TextInput
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                placeholder="name@center.com"
                                autoComplete="email"
                                hasError={Boolean(
                                    localErrors.email,
                                )}
                                onChange={(e) => {
                                    setData(
                                        'email',
                                        e.target.value,
                                    );

                                    setLocalErrors((current) => ({
                                        ...current,
                                        email: undefined,
                                    }));
                                }}
                            />

                            <InputError
                                message={localErrors.email}
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="center_id"
                                value="Language Center"
                            />

                            <select
                                id="center_id"
                                value={data.center_id}
                                onChange={(e) => {
                                    setData(
                                        'center_id',
                                        e.target.value,
                                    );
                                    setData('branch_id', '');

                                    setLocalErrors((current) => ({
                                        ...current,
                                        center_id: undefined,
                                        branch_id: undefined,
                                    }));
                                }}
                                className={
                                    `h-[40px] w-full rounded-[11px] border bg-white px-[16px] ` +
                                    `text-[13px] outline-none transition duration-200 ` +
                                    `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 ` +
                                    `lg:h-[56px] lg:px-[21px] lg:text-[19px] ` +
                                    (localErrors.center_id
                                        ? 'border-[#ff5656] bg-[#fffafa]'
                                        : 'border-[#dce0e8]')
                                }
                            >
                                <option value="">
                                    Select your language center
                                </option>

                                {availableCenters.map(
                                    (center) => (
                                        <option
                                            key={center.id}
                                            value={center.id}
                                        >
                                            {center.name}
                                        </option>
                                    ),
                                )}
                            </select>

                            <InputError
                                message={
                                    localErrors.center_id
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="branch_id"
                                value="Branch"
                            />

                            <select
                                id="branch_id"
                                value={data.branch_id}
                                disabled={!selectedCenter}
                                onChange={(e) => {
                                    setData(
                                        'branch_id',
                                        e.target.value,
                                    );

                                    setLocalErrors((current) => ({
                                        ...current,
                                        branch_id: undefined,
                                    }));
                                }}
                                className={
                                    `h-[40px] w-full rounded-[11px] border bg-white px-[16px] ` +
                                    `text-[13px] outline-none transition duration-200 ` +
                                    `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 ` +
                                    `disabled:cursor-not-allowed disabled:bg-[#f3f4f7] disabled:text-[#a9adb6] ` +
                                    `lg:h-[56px] lg:px-[21px] lg:text-[19px] ` +
                                    (localErrors.branch_id
                                        ? 'border-[#ff5656] bg-[#fffafa]'
                                        : 'border-[#dce0e8]')
                                }
                            >
                                <option value="">
                                    {selectedCenter
                                        ? 'Select your branch'
                                        : 'Select a center first'}
                                </option>

                                {selectedCenter?.branches.map(
                                    (branch) => (
                                        <option
                                            key={branch.id}
                                            value={branch.id}
                                        >
                                            {branch.name}
                                        </option>
                                    ),
                                )}
                            </select>

                            <InputError
                                message={
                                    localErrors.branch_id
                                }
                            />
                        </div>

                        <div>
                            <InputLabel value="Role" />

                            <div className="flex h-[40px] w-full items-center rounded-[11px] border border-[#dce0e8] bg-[#f7f8fb] px-[16px] text-[13px] text-[#555b68] lg:h-[56px] lg:px-[21px] lg:text-[19px]">
                                Student
                            </div>
                        </div>
                    </div>

                    <div className="mt-6 space-y-3 lg:mt-8">
                        <PrimaryButton type="submit">
                            Continue
                            <ArrowRight
                                className="ml-2"
                                size={17}
                                strokeWidth={2}
                            />
                        </PrimaryButton>

                        <Link
                            href={route('login')}
                            className="flex h-[40px] w-full items-center justify-center gap-2 rounded-[11px] border border-[#dce0e8] bg-transparent text-[12px] font-medium text-[#6f7581] transition hover:bg-white lg:h-[56px] lg:text-[17px]"
                        >
                            <ArrowLeft
                                size={16}
                                strokeWidth={1.8}
                            />
                            Back to Login
                        </Link>
                    </div>
                </form>
            ) : (
                <form onSubmit={submit} noValidate>
                    <div>
                        <InputLabel
                            htmlFor="password"
                            value="Password"
                        />

                        <PasswordInput
                            id="password"
                            name="password"
                            value={data.password}
                            placeholder="Create a strong password"
                            autoComplete="new-password"
                            hasError={Boolean(
                                localErrors.password,
                            )}
                            onChange={(e) => {
                                setData(
                                    'password',
                                    e.target.value,
                                );

                                setLocalErrors((current) => ({
                                    ...current,
                                    password: undefined,
                                }));
                            }}
                        />

                        <InputError
                            message={localErrors.password}
                        />

                        {data.password && (
                            <div className="mt-3 space-y-2">
                                <PasswordRequirement
                                    valid={
                                        passwordChecks.length
                                    }
                                >
                                    At least 8 characters
                                </PasswordRequirement>

                                <PasswordRequirement
                                    valid={
                                        passwordChecks.uppercase
                                    }
                                >
                                    One uppercase letter
                                </PasswordRequirement>

                                <PasswordRequirement
                                    valid={
                                        passwordChecks.number
                                    }
                                >
                                    One number
                                </PasswordRequirement>
                            </div>
                        )}
                    </div>

                    <div className="mt-[18px] lg:mt-[24px]">
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirm Password"
                        />

                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            value={
                                data.password_confirmation
                            }
                            placeholder="Re-enter your password"
                            autoComplete="new-password"
                            hasError={Boolean(
                                localErrors.password_confirmation,
                            )}
                            onChange={(e) => {
                                setData(
                                    'password_confirmation',
                                    e.target.value,
                                );

                                setLocalErrors((current) => ({
                                    ...current,
                                    password_confirmation:
                                        undefined,
                                }));
                            }}
                        />

                        <InputError
                            message={
                                localErrors.password_confirmation
                            }
                        />

                        {data.password_confirmation &&
                            passwordsMatch && (
                                <div className="mt-2">
                                    <PasswordRequirement valid>
                                        Passwords match
                                    </PasswordRequirement>
                                </div>
                            )}
                    </div>

                    <div className="mt-5">
                        <label className="flex cursor-pointer items-start gap-3">
                            <Checkbox
                                checked={data.terms}
                                onChange={(e) => {
                                    setData(
                                        'terms',
                                        e.target.checked,
                                    );

                                    setLocalErrors((current) => ({
                                        ...current,
                                        terms: undefined,
                                    }));
                                }}
                                className="mt-[2px] h-[16px] w-[16px] shrink-0"
                            />

                            <span className="text-[11px] leading-[18px] text-[#858b97] lg:text-[14px] lg:leading-[22px]">
                                I agree to the{' '}
                                <button
                                    type="button"
                                    className="font-semibold text-[#3947cf] hover:underline"
                                    onClick={(e) =>
                                        e.preventDefault()
                                    }
                                >
                                    Terms & Conditions
                                </button>{' '}
                                and{' '}
                                <button
                                    type="button"
                                    className="font-semibold text-[#3947cf] hover:underline"
                                    onClick={(e) =>
                                        e.preventDefault()
                                    }
                                >
                                    Privacy Policy
                                </button>{' '}
                                of LCMS.
                            </span>
                        </label>

                        <InputError
                            message={localErrors.terms}
                        />
                    </div>

                    <div className="mt-6 space-y-3 lg:mt-8">
                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                        >
                            Create Account
                            <ArrowRight
                                className="ml-2"
                                size={17}
                                strokeWidth={2}
                            />
                        </PrimaryButton>

                        <SecondaryButton
                            type="button"
                            onClick={() => {
                                setLocalErrors({});
                                setStep(1);
                            }}
                        >
                            <ArrowLeft
                                className="mr-2"
                                size={16}
                                strokeWidth={1.8}
                            />
                            Back
                        </SecondaryButton>
                    </div>
                </form>
            )}

            <p className="mt-8 hidden text-[12px] text-[#c3c7cf] lg:block">
                © 2026 LCMS
            </p>
        </GuestLayout>
    );
}

function RegistrationStepper({
    step,
}: {
    step: 1 | 2;
}) {
    return (
        <div className="mb-6 mt-3 lg:mb-8 lg:mt-7">
            <div className="flex items-center">
                <div className="relative flex flex-col items-center">
                    <div
                        className={
                            `flex h-[28px] w-[28px] items-center justify-center rounded-full text-[11px] font-bold lg:h-[32px] lg:w-[32px] lg:text-[13px] ` +
                            (step === 2
                                ? 'bg-[#18ad55] text-white'
                                : 'bg-[#3947cf] text-white')
                        }
                    >
                        {step === 2 ? (
                            <Check
                                size={16}
                                strokeWidth={2.5}
                            />
                        ) : (
                            '1'
                        )}
                    </div>
                </div>

                <div
                    className={
                        `mx-3 h-[2px] flex-1 lg:mx-4 ` +
                        (step === 2
                            ? 'bg-[#3947cf]'
                            : 'bg-[#dfe2eb]')
                    }
                />

                <div className="relative flex flex-col items-center">
                    <div
                        className={
                            `flex h-[28px] w-[28px] items-center justify-center rounded-full text-[11px] font-bold lg:h-[32px] lg:w-[32px] lg:text-[13px] ` +
                            (step === 2
                                ? 'bg-[#3947cf] text-white'
                                : 'bg-[#eef0f5] text-[#b9bec8]')
                        }
                    >
                        2
                    </div>
                </div>
            </div>

            <div className="mt-2 flex justify-between text-[9px] font-semibold lg:text-[11px]">
                <span
                    className={
                        step >= 1
                            ? 'text-[#3947cf]'
                            : 'text-[#b9bec8]'
                    }
                >
                    Account Info
                </span>

                <span
                    className={
                        step === 2
                            ? 'text-[#3947cf]'
                            : 'text-[#b9bec8]'
                    }
                >
                    Set Password
                </span>
            </div>
        </div>
    );
}

function PasswordRequirement({
    valid,
    children,
}: {
    valid: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            className={
                `flex items-center gap-2 text-[11px] lg:text-[13px] ` +
                (valid
                    ? 'text-[#16a34a]'
                    : 'text-[#9aa0ab]')
            }
        >
            <span
                className={
                    `flex h-[15px] w-[15px] items-center justify-center rounded-full ` +
                    (valid
                        ? 'bg-[#19ad55] text-white'
                        : 'border border-[#cdd1d9]')
                }
            >
                {valid && (
                    <Check
                        size={10}
                        strokeWidth={3}
                    />
                )}
            </span>

            {children}
        </div>
    );
}