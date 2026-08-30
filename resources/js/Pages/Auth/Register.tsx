import ApplicationLogo from '@/Components/ApplicationLogo';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

import axios from 'axios';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleCheckBig,
    Upload,
} from 'lucide-react';
import {
    ChangeEvent,
    FormEvent,
    useMemo,
    useState,
} from 'react';

type Center = {
    code: string;
    name: string;
};

type RegisterProps = {
    centers?: Center[];
};

type RegistrationData = {
    center_code: string;
    full_name: string;
    national_id_number: string;
    date_of_birth: string;
    city_of_residence: string;
    email: string;
    phone_number: string;
    personal_picture: File | null;
};

type LocalErrors = Partial<
    Record<
        | keyof RegistrationData
        | 'general',
        string
    >
>;

type ApiErrorResponse = {
    message?: string;
    errors?: Record<string, string[]>;
};

export default function Register({
    centers = [],
}: RegisterProps) {
    const [step, setStep] = useState<1 | 2>(1);
    const [submitted, setSubmitted] =
        useState(false);
    const [processing, setProcessing] =
        useState(false);
    const [localErrors, setLocalErrors] =
        useState<LocalErrors>({});

    const [data, setData] =
        useState<RegistrationData>({
            center_code: '',
            full_name: '',
            national_id_number: '',
            date_of_birth: '',
            city_of_residence: '',
            email: '',
            phone_number: '',
            personal_picture: null,
        });

    const selectedCenter = useMemo(
        () =>
            centers.find(
                (center) =>
                    center.code === data.center_code,
            ),
        [centers, data.center_code],
    );

    const updateField = <
        K extends keyof RegistrationData,
    >(
        field: K,
        value: RegistrationData[K],
    ) => {
        setData((current) => ({
            ...current,
            [field]: value,
        }));

        setLocalErrors((current) => ({
            ...current,
            [field]: undefined,
            general: undefined,
        }));
    };

    const validateStepOne = (): boolean => {
        const errors: LocalErrors = {};

        if (!data.full_name.trim()) {
            errors.full_name =
                'Full name is required.';
        }

        if (!data.national_id_number.trim()) {
            errors.national_id_number =
                'National ID Number is required.';
        }

        if (!data.date_of_birth) {
            errors.date_of_birth =
                'Date of birth is required.';
        } else {
            const birthDate =
                new Date(
                    `${data.date_of_birth}T00:00:00`,
                );

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            if (
                Number.isNaN(
                    birthDate.getTime(),
                )
                || birthDate > today
            ) {
                errors.date_of_birth =
                    'Enter a valid date of birth.';
            }
        }

        if (!data.city_of_residence.trim()) {
            errors.city_of_residence =
                'City of residence is required.';
        }

        setLocalErrors(errors);

        return Object.keys(errors).length === 0;
    };

    const validateStepTwo = (): boolean => {
        const errors: LocalErrors = {};

        if (!data.email.trim()) {
            errors.email =
                'Email address is required.';
        } else if (
            !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
                data.email,
            )
        ) {
            errors.email =
                'Enter a valid email address.';
        }

        if (!data.phone_number.trim()) {
            errors.phone_number =
                'Phone number is required.';
        } else if (
            !/^\+[1-9][0-9]{7,14}$/.test(
                data.phone_number.trim(),
            )
        ) {
            errors.phone_number =
                'Use an international phone number, for example +970599123456.';
        }

        if (!data.center_code) {
            errors.center_code =
                'Please select a language center.';
        }

        if (
            data.personal_picture
            && ![
                'image/jpeg',
                'image/png',
                'image/webp',
            ].includes(
                data.personal_picture.type,
            )
        ) {
            errors.personal_picture =
                'The picture must be JPG, PNG, or WEBP.';
        } else if (
            data.personal_picture
            && data.personal_picture.size
                > 5 * 1024 * 1024
        ) {
            errors.personal_picture =
                'The picture may not be larger than 5 MB.';
        }

        setLocalErrors(errors);

        return Object.keys(errors).length === 0;
    };

    const continueToContact = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (!validateStepOne()) {
            return;
        }

        setLocalErrors({});
        setStep(2);
    };

    const handlePictureChange = (
        event: ChangeEvent<HTMLInputElement>,
    ) => {
        const file =
            event.target.files?.[0] ?? null;

        updateField(
            'personal_picture',
            file,
        );
    };

    const submit = async (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (
            processing
            || !validateStepTwo()
        ) {
            return;
        }

        setProcessing(true);
        setLocalErrors({});

        const payload = new FormData();

        payload.append(
            'full_name',
            data.full_name.trim(),
        );

        payload.append(
            'national_id_number',
            data.national_id_number.trim(),
        );

        payload.append(
            'date_of_birth',
            data.date_of_birth,
        );

        payload.append(
            'city_of_residence',
            data.city_of_residence.trim(),
        );

        payload.append(
            'email',
            data.email.trim(),
        );

        payload.append(
            'phone_number',
            data.phone_number.trim(),
        );

        if (data.personal_picture) {
            payload.append(
                'personal_picture',
                data.personal_picture,
            );
        }

        try {
            await axios.post(
                `/api/v1/centers/${encodeURIComponent(
                    data.center_code,
                )}/registration-requests`,
                payload,
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            setSubmitted(true);
        } catch (error) {
            if (axios.isAxiosError<ApiErrorResponse>(
                error,
            )) {
                const response =
                    error.response;

                if (
                    response?.status === 422
                    && response.data.errors
                ) {
                    const serverErrors:
                        LocalErrors = {};

                    Object.entries(
                        response.data.errors,
                    ).forEach(
                        ([field, messages]) => {
                            if (
                                messages.length > 0
                            ) {
                                serverErrors[
                                    field as keyof RegistrationData
                                ] = messages[0];
                            }
                        },
                    );

                    if (
                        Object.keys(
                            serverErrors,
                        ).length > 0
                    ) {
                        setLocalErrors(
                            serverErrors,
                        );

                        return;
                    }
                }

                setLocalErrors({
                    general:
                        response?.data
                            ?.message
                        ?? 'The registration request could not be submitted.',
                });

                return;
            }

            setLocalErrors({
                general:
                    'The registration request could not be submitted.',
            });
        } finally {
            setProcessing(false);
        }
    };

    if (submitted) {
        return (
            <GuestLayout
                mobileTitle="Request Submitted"
                mobileSubtitle=""
            >
                <Head title="Request Submitted" />

                <section className="flex flex-col items-center text-center">
                    <ApplicationLogo
                        className="mb-10 hidden h-auto w-[185px] lg:block"
                    />

                    <h1 className="hidden text-[30px] font-bold tracking-[-0.025em] text-[#252832] lg:block">
                        Request Submitted
                    </h1>

                    <div className="mt-4 flex h-[82px] w-[82px] items-center justify-center rounded-full border-2 border-[#16b95a] bg-[#ecfff3] text-[#16b95a] lg:mt-8">
                        <CircleCheckBig
                            size={42}
                            strokeWidth={1.8}
                        />
                    </div>

                    <h2 className="mt-5 text-[17px] font-bold text-[#12a84e] lg:text-[21px]">
                        Registration Request Sent
                    </h2>

                    <p className="mt-4 max-w-[430px] text-[12px] leading-[20px] text-[#a4a9b4] lg:text-[16px] lg:leading-[26px]">
                        Your request has been sent to the
                        language center for review. If it is
                        approved, your sign-in credentials
                        will be sent to your email address.
                    </p>

                    <p className="mt-3 text-[11px] text-[#b1b5bd] lg:text-[14px]">
                        Request for:{' '}
                        <span className="font-semibold text-[#464b56]">
                            {data.full_name}
                        </span>
                    </p>

                    {selectedCenter && (
                        <p className="mt-1 text-[11px] text-[#b1b5bd] lg:text-[14px]">
                            {selectedCenter.name}
                        </p>
                    )}

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
            mobileTitle="Request Access"
            mobileSubtitle="Submit your information to request access to the LCMS platform."
        >
            <Head title="Request Access" />

            <div className="hidden lg:block">
                <ApplicationLogo
                    className="mb-8 h-auto w-[185px]"
                />

                <h1 className="text-[30px] font-bold tracking-[-0.025em] text-[#252832]">
                    Request Access
                </h1>

                <p className="mt-2 text-[15px] leading-[24px] text-[#adb1ba]">
                    Submit your information to the language
                    center. Your account will be created only
                    after approval.
                </p>
            </div>

            <RegistrationStepper
                step={step}
            />

            {localErrors.general && (
                <div
                    role="alert"
                    className="mb-5 rounded-[10px] border border-[#ff9393] bg-[#fff0f0] px-4 py-3 text-[12px] text-[#ef3434] lg:text-[15px]"
                >
                    {localErrors.general}
                </div>
            )}

            {step === 1 ? (
                <form
                    onSubmit={
                        continueToContact
                    }
                    noValidate
                >
                    <div className="space-y-[15px] lg:space-y-[20px]">
                        <div>
                            <InputLabel
                                htmlFor="full_name"
                                value="Full Name"
                            />

                            <TextInput
                                id="full_name"
                                name="full_name"
                                value={
                                    data.full_name
                                }
                                placeholder="Enter your full name"
                                autoComplete="name"
                                isFocused
                                hasError={Boolean(
                                    localErrors.full_name,
                                )}
                                onChange={(event) =>
                                    updateField(
                                        'full_name',
                                        event.target
                                            .value,
                                    )
                                }
                            />

                            <InputError
                                message={
                                    localErrors.full_name
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="national_id_number"
                                value="National ID Number"
                            />

                            <TextInput
                                id="national_id_number"
                                name="national_id_number"
                                value={
                                    data.national_id_number
                                }
                                placeholder="Enter your National ID Number"
                                autoComplete="off"
                                hasError={Boolean(
                                    localErrors.national_id_number,
                                )}
                                onChange={(event) =>
                                    updateField(
                                        'national_id_number',
                                        event.target
                                            .value,
                                    )
                                }
                            />

                            <InputError
                                message={
                                    localErrors.national_id_number
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="date_of_birth"
                                value="Date of Birth"
                            />

                            <input
                                id="date_of_birth"
                                type="date"
                                name="date_of_birth"
                                value={
                                    data.date_of_birth
                                }
                                onChange={(event) =>
                                    updateField(
                                        'date_of_birth',
                                        event.target
                                            .value,
                                    )
                                }
                                className={
                                    `h-[40px] w-full rounded-[11px] border bg-white px-[16px] text-[13px] outline-none transition duration-200 ` +
                                    `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 lg:h-[56px] lg:px-[21px] lg:text-[19px] ` +
                                    (localErrors.date_of_birth
                                        ? 'border-[#ff5656] bg-[#fffafa]'
                                        : 'border-[#dce0e8]')
                                }
                            />

                            <InputError
                                message={
                                    localErrors.date_of_birth
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="city_of_residence"
                                value="City of Residence"
                            />

                            <TextInput
                                id="city_of_residence"
                                name="city_of_residence"
                                value={
                                    data.city_of_residence
                                }
                                placeholder="Enter your city"
                                autoComplete="address-level2"
                                hasError={Boolean(
                                    localErrors.city_of_residence,
                                )}
                                onChange={(event) =>
                                    updateField(
                                        'city_of_residence',
                                        event.target
                                            .value,
                                    )
                                }
                            />

                            <InputError
                                message={
                                    localErrors.city_of_residence
                                }
                            />
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
                <form
                    onSubmit={submit}
                    noValidate
                >
                    <div className="space-y-[15px] lg:space-y-[20px]">
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
                                placeholder="name@example.com"
                                autoComplete="email"
                                hasError={Boolean(
                                    localErrors.email,
                                )}
                                onChange={(event) =>
                                    updateField(
                                        'email',
                                        event.target
                                            .value,
                                    )
                                }
                            />

                            <InputError
                                message={
                                    localErrors.email
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="phone_number"
                                value="Phone Number"
                            />

                            <TextInput
                                id="phone_number"
                                type="tel"
                                name="phone_number"
                                value={
                                    data.phone_number
                                }
                                placeholder="+970599123456"
                                autoComplete="tel"
                                hasError={Boolean(
                                    localErrors.phone_number,
                                )}
                                onChange={(event) =>
                                    updateField(
                                        'phone_number',
                                        event.target
                                            .value,
                                    )
                                }
                            />

                            <InputError
                                message={
                                    localErrors.phone_number
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="center_code"
                                value="Language Center"
                            />

                            <select
                                id="center_code"
                                name="center_code"
                                value={
                                    data.center_code
                                }
                                disabled={
                                    centers.length
                                    === 0
                                }
                                onChange={(event) =>
                                    updateField(
                                        'center_code',
                                        event.target
                                            .value,
                                    )
                                }
                                className={
                                    `h-[40px] w-full rounded-[11px] border bg-white px-[16px] text-[13px] outline-none transition duration-200 ` +
                                    `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 disabled:cursor-not-allowed disabled:bg-[#f3f4f7] ` +
                                    `lg:h-[56px] lg:px-[21px] lg:text-[19px] ` +
                                    (localErrors.center_code
                                        ? 'border-[#ff5656] bg-[#fffafa]'
                                        : 'border-[#dce0e8]')
                                }
                            >
                                <option value="">
                                    {centers.length
                                        > 0
                                        ? 'Select your language center'
                                        : 'No language centers are available'}
                                </option>

                                {centers.map(
                                    (center) => (
                                        <option
                                            key={
                                                center.code
                                            }
                                            value={
                                                center.code
                                            }
                                        >
                                            {
                                                center.name
                                            }
                                        </option>
                                    ),
                                )}
                            </select>

                            <InputError
                                message={
                                    localErrors.center_code
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="personal_picture"
                                value="Personal Picture (Optional)"
                            />

                            <label
                                htmlFor="personal_picture"
                                className={
                                    `flex min-h-[52px] cursor-pointer items-center gap-3 rounded-[11px] border bg-white px-[16px] text-[12px] text-[#727887] transition hover:bg-[#fafbfc] lg:min-h-[62px] lg:px-[21px] lg:text-[15px] ` +
                                    (localErrors.personal_picture
                                        ? 'border-[#ff5656] bg-[#fffafa]'
                                        : 'border-[#dce0e8]')
                                }
                            >
                                <Upload
                                    size={18}
                                    strokeWidth={1.8}
                                />

                                <span className="min-w-0 flex-1 truncate">
                                    {data.personal_picture
                                        ? data
                                              .personal_picture
                                              .name
                                        : 'Choose JPG, PNG, or WEBP image'}
                                </span>
                            </label>

                            <input
                                id="personal_picture"
                                name="personal_picture"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={
                                    handlePictureChange
                                }
                            />

                            <InputError
                                message={
                                    localErrors.personal_picture
                                }
                            />

                            <p className="mt-1 text-[10px] text-[#aeb3bd] lg:text-[12px]">
                                Maximum file size: 5 MB.
                            </p>
                        </div>
                    </div>

                    <div className="mt-6 space-y-3 lg:mt-8">
                        <PrimaryButton
                            type="submit"
                            disabled={
                                processing
                                || centers.length
                                    === 0
                            }
                        >
                            {processing
                                ? 'Submitting...'
                                : 'Submit Request'}

                            {!processing && (
                                <ArrowRight
                                    className="ml-2"
                                    size={17}
                                    strokeWidth={2}
                                />
                            )}
                        </PrimaryButton>

                        <SecondaryButton
                            type="button"
                            disabled={processing}
                            onClick={() => {
                                setLocalErrors(
                                    {},
                                );
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
                <span className="text-[#3947cf]">
                    Personal Info
                </span>

                <span
                    className={
                        step === 2
                            ? 'text-[#3947cf]'
                            : 'text-[#b9bec8]'
                    }
                >
                    Contact & Center
                </span>
            </div>
        </div>
    );
}
