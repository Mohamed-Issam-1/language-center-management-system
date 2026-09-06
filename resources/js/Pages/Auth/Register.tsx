import ApplicationLogo from '@/Components/ApplicationLogo';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Camera,
    Check,
    CircleCheckBig,
    UserRound,
} from 'lucide-react';
import {
    ChangeEvent,
    FormEvent,
    ReactNode,
    SelectHTMLAttributes,
    useEffect,
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

type Step = 1 | 2;

type FormDataState = {
    center_code: string;
    full_name: string;
    national_id_number: string;
    birth_day: string;
    birth_month: string;
    birth_year: string;
    city_of_residence: string;
    email: string;
    country_code: string;
    local_phone_number: string;
    personal_picture: File | null;
};

type FormErrors = Partial<
    Record<
        | 'center_code'
        | 'full_name'
        | 'national_id_number'
        | 'date_of_birth'
        | 'city_of_residence'
        | 'email'
        | 'phone_number'
        | 'personal_picture'
        | 'general',
        string
    >
>;

type ApiValidationErrors = Record<string, string[]>;

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const countryCodes = [
    { code: '+970', label: 'PS +970' },
    { code: '+962', label: 'JO +962' },
    { code: '+966', label: 'SA +966' },
    { code: '+971', label: 'AE +971' },
    { code: '+20', label: 'EG +20' },
    { code: '+1', label: 'US +1' },
];

const initialFormData: FormDataState = {
    center_code: '',
    full_name: '',
    national_id_number: '',
    birth_day: '',
    birth_month: '',
    birth_year: '',
    city_of_residence: '',
    email: '',
    country_code: '+970',
    local_phone_number: '',
    personal_picture: null,
};

export default function Register({
    centers = [],
}: RegisterProps) {
    const [step, setStep] = useState<Step>(1);
    const [data, setData] = useState<FormDataState>(initialFormData);
    const [errors, setErrors] = useState<FormErrors>({});
    const [processing, setProcessing] = useState(false);
    const [submitted, setSubmitted] = useState(false);
    const [picturePreview, setPicturePreview] = useState<string | null>(
        null,
    );

    const currentYear = new Date().getFullYear();

    const years = useMemo(
        () =>
            Array.from(
                { length: currentYear - 1899 },
                (_, index) => currentYear - index,
            ),
        [currentYear],
    );

    const selectedCenter = useMemo(
        () =>
            centers.find(
                (center) =>
                    center.code === data.center_code,
            ),
        [centers, data.center_code],
    );

    useEffect(() => {
        if (!data.personal_picture) {
            setPicturePreview(null);

            return;
        }

        const previewUrl = URL.createObjectURL(data.personal_picture);

        setPicturePreview(previewUrl);

        return () => {
            URL.revokeObjectURL(previewUrl);
        };
    }, [data.personal_picture]);


    const phoneNumber =
        data.country_code + data.local_phone_number;

    const dateOfBirth =
        data.birth_year &&
        data.birth_month &&
        data.birth_day
            ? `${data.birth_year}-${data.birth_month.padStart(
                  2,
                  '0',
              )}-${data.birth_day.padStart(2, '0')}`
            : '';

    const clearError = (field: keyof FormErrors) => {
        setErrors((current) => ({
            ...current,
            [field]: undefined,
            general: undefined,
        }));
    };

    const setValidationErrors = (
        nextErrors: FormErrors,
        firstError?: string,
    ) => {
        setErrors({
            ...nextErrors,
            general: firstError,
        });
    };

    const validateDate = () => {
        if (!dateOfBirth) {
            return false;
        }

        const year = Number(data.birth_year);
        const month = Number(data.birth_month);
        const day = Number(data.birth_day);

        const date = new Date(
            Date.UTC(year, month - 1, day),
        );

        return (
            date.getUTCFullYear() === year &&
            date.getUTCMonth() === month - 1 &&
            date.getUTCDate() === day &&
            date <= new Date()
        );
    };

    const validateStepOne = () => {
        const nextErrors: FormErrors = {};

        if (!data.full_name.trim()) {
            nextErrors.full_name =
                'Please enter your full name.';
        }

        if (!data.national_id_number.trim()) {
            nextErrors.national_id_number =
                'Please enter your national ID number.';
        } else if (data.national_id_number.trim().length > 50) {
            nextErrors.national_id_number =
                'National ID must not exceed 50 characters.';
        }

        if (!validateDate()) {
            nextErrors.date_of_birth =
                'Please enter a valid date of birth.';
        }

        if (!data.city_of_residence.trim()) {
            nextErrors.city_of_residence =
                'Please enter your city of residence.';
        }

        if (!data.email.trim()) {
            nextErrors.email =
                'Please enter your email address.';
        } else if (
            !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)
        ) {
            nextErrors.email =
                'Please enter a valid email address.';
        }

        if (!/^\d{7,12}$/.test(data.local_phone_number)) {
            nextErrors.phone_number =
                'Please enter a valid phone number.';
        }

        if (!data.center_code) {
            nextErrors.center_code =
                'Please select a language center.';
        }

        if (data.personal_picture) {
            const allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp',
            ];

            if (
                !allowedTypes.includes(
                    data.personal_picture.type,
                )
            ) {
                nextErrors.personal_picture =
                    'Photo must be JPG, PNG, or WEBP.';
            } else if (
                data.personal_picture.size >
                5 * 1024 * 1024
            ) {
                nextErrors.personal_picture =
                    'Photo may not be larger than 5 MB.';
            }
        }

        const firstError =
            nextErrors.full_name ||
            nextErrors.national_id_number ||
            nextErrors.date_of_birth ||
            nextErrors.city_of_residence ||
            nextErrors.email ||
            nextErrors.phone_number ||
            nextErrors.center_code ||
            nextErrors.personal_picture;

        setValidationErrors(nextErrors, firstError);

        return !firstError;
    };

    const continueToReview = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (!validateStepOne()) {
            return;
        }

        setErrors({});
        setStep(2);

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    const handlePictureChange = (
        event: ChangeEvent<HTMLInputElement>,
    ) => {
        const file = event.target.files?.[0] ?? null;

        setData((current) => ({
            ...current,
            personal_picture: file,
        }));

        clearError('personal_picture');
    };

    const mapApiErrors = (
        apiErrors: ApiValidationErrors,
    ): FormErrors => ({
        full_name: apiErrors.full_name?.[0],
        national_id_number:
            apiErrors.national_id_number?.[0],
        date_of_birth:
            apiErrors.date_of_birth?.[0],
        city_of_residence:
            apiErrors.city_of_residence?.[0],
        email: apiErrors.email?.[0],
        phone_number:
            apiErrors.phone_number?.[0],
        personal_picture:
            apiErrors.personal_picture?.[0],
    });

    const submit = async (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        if (!validateStepOne()) {
            setStep(1);

            return;
        }

        setProcessing(true);
        setErrors({});

        const payload = new FormData();

        payload.append(
            'full_name',
            data.full_name.trim(),
        );

        payload.append(
            'national_id_number',
            data.national_id_number,
        );

        payload.append(
            'date_of_birth',
            dateOfBirth,
        );

        payload.append(
            'city_of_residence',
            data.city_of_residence.trim(),
        );

        payload.append(
            'email',
            data.email.trim().toLowerCase(),
        );

        payload.append(
            'phone_number',
            phoneNumber,
        );


        if (data.personal_picture) {
            payload.append(
                'personal_picture',
                data.personal_picture,
            );
        }

        try {
            const response = await fetch(
                `/api/v1/centers/${encodeURIComponent(
                    data.center_code,
                )}/registration-requests`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                    },
                    body: payload,
                },
            );

            const result = await response
                .json()
                .catch(() => ({}));

            if (!response.ok) {
                if (
                    response.status === 422 &&
                    result.errors
                ) {
                    const mappedErrors =
                        mapApiErrors(result.errors);

                    const stepOneError =
                        mappedErrors.full_name ||
                        mappedErrors.national_id_number ||
                        mappedErrors.date_of_birth ||
                        mappedErrors.city_of_residence ||
                        mappedErrors.email ||
                        mappedErrors.phone_number ||
                        mappedErrors.personal_picture;

                    if (stepOneError) {
                        setStep(1);
                    }

                    setErrors({
                        ...mappedErrors,
                        general:
                            stepOneError ||
                            result.message ||
                            'Please review the form and try again.',
                    });

                    return;
                }

                setErrors({
                    general:
                        result.message ||
                        'Registration could not be submitted. Please try again.',
                });

                return;
            }

            setSubmitted(true);

            window.scrollTo({
                top: 0,
                behavior: 'smooth',
            });
        } catch {
            setErrors({
                general:
                    'Unable to connect to the server. Please try again.',
            });
        } finally {
            setProcessing(false);
        }
    };

    if (submitted) {
        return (
            <GuestLayout
                mobileTitle="Registration Submitted"
                mobileSubtitle=""
            >
                <Head title="Registration Submitted" />

                <section className="w-full">
                    <ApplicationLogo
                        variant="blue"
                        className="mb-8 hidden h-auto w-[185px] lg:block"
                    />

                    <h1 className="hidden text-[30px] font-bold tracking-[-0.025em] text-[#252832] lg:block">
                        Registration Submitted
                    </h1>

                    <div className="flex flex-col items-center text-center">
                        <div className="mt-7 flex h-[80px] w-[80px] items-center justify-center rounded-full border-[3px] border-[#16a34a] bg-[#ebfff1] text-[#16a34a]">
                            <CircleCheckBig
                                size={39}
                                strokeWidth={2}
                            />
                        </div>

                        <h2 className="mt-6 text-[21px] font-bold text-[#10a84f]">
                            Request Submitted!
                        </h2>

                        <p className="mt-4 max-w-[420px] text-[14px] leading-[25px] text-[#a4a9b4]">
                            Your registration request has been
                            submitted. You will be notified once
                            your account is approved and activated
                            by the center administrator.
                        </p>

                        <div className="mt-5 w-full rounded-[12px] border border-[#e1e4ed] px-5 py-5 text-left">
                            <SummaryRow
                                label="NAME"
                                value={data.full_name}
                            />

                            <SummaryRow
                                label="CENTER"
                                value={
                                    selectedCenter?.name ??
                                    data.center_code
                                }
                            />

                            <SummaryRow
                                label="EMAIL"
                                value={data.email}
                            />

                            <SummaryRow
                                label="PHONE"
                                value={phoneNumber}
                            />

                            <SummaryRow
                                label="CITY"
                                value={
                                    data.city_of_residence
                                }
                                last
                            />
                        </div>

                        <Link
                            href={route('login')}
                            className="mt-6 flex h-[48px] w-full items-center justify-center gap-2 rounded-[11px] bg-[#3f46d3] px-5 text-[14px] font-semibold text-white transition hover:bg-[#353cc3] focus:outline-none focus:ring-4 focus:ring-[#3f46d3]/15"
                        >
                            Back to Login

                            <ArrowRight
                                size={17}
                                strokeWidth={2}
                            />
                        </Link>
                    </div>

                    <Footer />
                </section>
            </GuestLayout>
        );
    }

    return (
        <GuestLayout
            mobileTitle="Create Account"
            mobileSubtitle="Complete the form to request access to the LCMS platform."
        >
            <Head title="Create Account" />

            <ApplicationLogo
                variant="blue"
                className="auth-register-logo mb-7 hidden h-auto w-[185px] lg:block"
            />

            <div className="hidden lg:block">
                <h1 className="auth-register-title text-[30px] font-bold tracking-[-0.025em] text-[#252832]">
                    Create Account
                </h1>

                <p className="auth-register-subtitle mt-1 text-[14px] leading-[24px] text-[#adb1ba]">
                    Complete the form to request access to the
                    LCMS platform.
                </p>
            </div>

            <RegistrationStepper step={step} />

            {errors.general && (
                <RegistrationAlert>
                    {errors.general}
                </RegistrationAlert>
            )}

            {step === 1 ? (
                <form
                    onSubmit={continueToReview}
                    noValidate
                    className="auth-register-form"
                >
                    <PersonalPictureField
                        preview={picturePreview}
                        error={
                            errors.personal_picture
                        }
                        onChange={
                            handlePictureChange
                        }
                    />

                    <div className="auth-register-fields mt-5 space-y-[14px]">
                        <div>
                            <InputLabel
                                htmlFor="full_name"
                                value="Full Name"
                            />

                            <TextInput
                                id="full_name"
                                name="full_name"
                                value={data.full_name}
                                placeholder="e.g. Mohammad Ahmad Znaid"
                                autoComplete="name"
                                maxLength={255}
                                hasError={Boolean(
                                    errors.full_name,
                                )}
                                onChange={(event) => {
                                    setData(
                                        (current) => ({
                                            ...current,
                                            full_name:
                                                event
                                                    .target
                                                    .value,
                                        }),
                                    );

                                    clearError(
                                        'full_name',
                                    );
                                }}
                            />

                            <InputError
                                message={
                                    errors.full_name
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="national_id_number"
                                value="National ID"
                            />

                            <TextInput
                                id="national_id_number"
                                name="national_id_number"
                                value={
                                    data.national_id_number
                                }
                                placeholder="e.g. 1234567890"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={12}
                                hasError={Boolean(
                                    errors.national_id_number,
                                )}
                                onChange={(event) => {
                                    const value =
                                        event.target.value
                                            .replace(
                                                /\D/g,
                                                '',
                                            )
                                            .slice(
                                                0,
                                                12,
                                            );

                                    setData(
                                        (current) => ({
                                            ...current,
                                            national_id_number:
                                                value,
                                        }),
                                    );

                                    clearError(
                                        'national_id_number',
                                    );
                                }}
                            />

                            <p className="mt-1 text-[10px] text-[#c2c6ce]">
                                Required, maximum 50 characters
                            </p>

                            <InputError
                                message={
                                    errors.national_id_number
                                }
                            />
                        </div>

                        <div>
                            <InputLabel value="Date of Birth" />

                            <div className="grid grid-cols-[72px_1fr_90px] gap-2">
                                <SelectInput
                                    aria-label="Birth day"
                                    value={data.birth_day}
                                    hasError={Boolean(
                                        errors.date_of_birth,
                                    )}
                                    onChange={(event) => {
                                        setData(
                                            (current) => ({
                                                ...current,
                                                birth_day:
                                                    event
                                                        .target
                                                        .value,
                                            }),
                                        );

                                        clearError(
                                            'date_of_birth',
                                        );
                                    }}
                                >
                                    <option value="">
                                        Day
                                    </option>

                                    {Array.from(
                                        { length: 31 },
                                        (_, index) => (
                                            <option
                                                key={
                                                    index +
                                                    1
                                                }
                                                value={
                                                    index +
                                                    1
                                                }
                                            >
                                                {String(
                                                    index +
                                                        1,
                                                ).padStart(
                                                    2,
                                                    '0',
                                                )}
                                            </option>
                                        ),
                                    )}
                                </SelectInput>

                                <SelectInput
                                    aria-label="Birth month"
                                    value={
                                        data.birth_month
                                    }
                                    hasError={Boolean(
                                        errors.date_of_birth,
                                    )}
                                    onChange={(event) => {
                                        setData(
                                            (current) => ({
                                                ...current,
                                                birth_month:
                                                    event
                                                        .target
                                                        .value,
                                            }),
                                        );

                                        clearError(
                                            'date_of_birth',
                                        );
                                    }}
                                >
                                    <option value="">
                                        Month
                                    </option>

                                    {months.map(
                                        (
                                            month,
                                            index,
                                        ) => (
                                            <option
                                                key={
                                                    month
                                                }
                                                value={
                                                    index +
                                                    1
                                                }
                                            >
                                                {
                                                    month
                                                }
                                            </option>
                                        ),
                                    )}
                                </SelectInput>

                                <SelectInput
                                    aria-label="Birth year"
                                    value={data.birth_year}
                                    hasError={Boolean(
                                        errors.date_of_birth,
                                    )}
                                    onChange={(event) => {
                                        setData(
                                            (current) => ({
                                                ...current,
                                                birth_year:
                                                    event
                                                        .target
                                                        .value,
                                            }),
                                        );

                                        clearError(
                                            'date_of_birth',
                                        );
                                    }}
                                >
                                    <option value="">
                                        Year
                                    </option>

                                    {years.map((year) => (
                                        <option
                                            key={year}
                                            value={year}
                                        >
                                            {year}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>

                            <InputError
                                message={
                                    errors.date_of_birth
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
                                placeholder="e.g. Riyadh"
                                autoComplete="address-level2"
                                maxLength={150}
                                hasError={Boolean(
                                    errors.city_of_residence,
                                )}
                                onChange={(event) => {
                                    setData(
                                        (current) => ({
                                            ...current,
                                            city_of_residence:
                                                event
                                                    .target
                                                    .value,
                                        }),
                                    );

                                    clearError(
                                        'city_of_residence',
                                    );
                                }}
                            />

                            <InputError
                                message={
                                    errors.city_of_residence
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
                                placeholder="name@example.com"
                                autoComplete="email"
                                maxLength={255}
                                hasError={Boolean(
                                    errors.email,
                                )}
                                onChange={(event) => {
                                    setData(
                                        (current) => ({
                                            ...current,
                                            email:
                                                event
                                                    .target
                                                    .value,
                                        }),
                                    );

                                    clearError('email');
                                }}
                            />

                            <InputError
                                message={errors.email}
                            />
                        </div>

                        <div>
                            <InputLabel value="Phone Number" />

                            <div className="grid grid-cols-[100px_1fr] gap-2">
                                <SelectInput
                                    aria-label="Country code"
                                    value={
                                        data.country_code
                                    }
                                    hasError={Boolean(
                                        errors.phone_number,
                                    )}
                                    onChange={(event) => {
                                        setData(
                                            (current) => ({
                                                ...current,
                                                country_code:
                                                    event
                                                        .target
                                                        .value,
                                            }),
                                        );

                                        clearError(
                                            'phone_number',
                                        );
                                    }}
                                >
                                    {countryCodes.map(
                                        (country) => (
                                            <option
                                                key={
                                                    country.code
                                                }
                                                value={
                                                    country.code
                                                }
                                            >
                                                {
                                                    country.label
                                                }
                                            </option>
                                        ),
                                    )}
                                </SelectInput>

                                <TextInput
                                    name="local_phone_number"
                                    value={
                                        data.local_phone_number
                                    }
                                    placeholder="5xxxxxxxx"
                                    inputMode="numeric"
                                    autoComplete="tel-national"
                                    maxLength={12}
                                    hasError={Boolean(
                                        errors.phone_number,
                                    )}
                                    onChange={(event) => {
                                        const value =
                                            event.target.value
                                                .replace(
                                                    /\D/g,
                                                    '',
                                                )
                                                .slice(
                                                    0,
                                                    12,
                                                );

                                        setData(
                                            (current) => ({
                                                ...current,
                                                local_phone_number:
                                                    value,
                                            }),
                                        );

                                        clearError(
                                            'phone_number',
                                        );
                                    }}
                                />
                            </div>

                            <InputError
                                message={
                                    errors.phone_number
                                }
                            />
                        </div>

                        <div>
                            <InputLabel
                                htmlFor="center_code"
                                value="Language Center"
                            />

                            <SelectInput
                                id="center_code"
                                name="center_code"
                                value={data.center_code}
                                disabled={centers.length === 0}
                                hasError={Boolean(
                                    errors.center_code,
                                )}
                                onChange={(event) => {
                                    setData(
                                        (current) => ({
                                            ...current,
                                            center_code:
                                                event
                                                    .target
                                                    .value,
                                        }),
                                    );

                                    clearError(
                                        'center_code',
                                    );
                                }}
                            >
                                <option value="">
                                    {centers.length > 0
                                        ? 'Select your language center'
                                        : 'No language centers are available'}
                                </option>

                                {centers.map((center) => (
                                    <option
                                        key={center.code}
                                        value={center.code}
                                    >
                                        {center.name}
                                    </option>
                                ))}
                            </SelectInput>

                            <InputError
                                message={
                                    errors.center_code
                                }
                            />
                        </div>
                    </div>

                    <div className="auth-register-actions mt-5 space-y-3">
                        <PrimaryButton
                            type="submit"
                            className="gap-2"
                        >
                            Review Application

                            <ArrowRight
                                size={17}
                                strokeWidth={2}
                            />
                        </PrimaryButton>

                        <Link
                            href={route('login')}
                            className="flex h-[40px] w-full items-center justify-center gap-2 rounded-[11px] border border-[#dce0e8] text-[12px] font-medium text-[#6f7581] transition hover:bg-white lg:h-[48px] lg:text-[15px]"
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
                    <ApplicantSummary
                        name={data.full_name}
                        email={data.email}
                    />

                    <div className="mt-5 rounded-[12px] border border-[#e1e4ed] px-5 py-5 text-left">
                        <SummaryRow
                            label="CENTER"
                            value={
                                selectedCenter?.name ??
                                data.center_code
                            }
                        />

                        <SummaryRow
                            label="ID"
                            value={
                                data.national_id_number
                            }
                        />

                        <SummaryRow
                            label="DOB"
                            value={dateOfBirth}
                        />

                        <SummaryRow
                            label="CITY"
                            value={
                                data.city_of_residence
                            }
                        />

                        <SummaryRow
                            label="EMAIL"
                            value={data.email}
                        />

                        <SummaryRow
                            label="PHONE"
                            value={phoneNumber}
                            last
                        />
                    </div>

                    <div className="mt-5 rounded-[10px] border border-[#cfd8ff] bg-[#eef3ff] px-[14px] py-[12px]">
                        <p className="text-[11px] leading-[18px] text-[#5263c9]">
                            This submission creates a pending
                            registration request only. Your account
                            and sign-in credentials are created
                            after administrative approval.
                        </p>
                    </div>

                    <div className="auth-register-actions mt-5 space-y-3">
                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                            className="gap-2"
                        >
                            {processing ? (
                                <>
                                    <Spinner />
                                    Submitting...
                                </>
                            ) : (
                                <>
                                    Submit Registration

                                    <ArrowRight
                                        size={17}
                                        strokeWidth={2}
                                    />
                                </>
                            )}
                        </PrimaryButton>

                        <SecondaryButton
                            type="button"
                            disabled={processing}
                            onClick={() => {
                                setErrors({});
                                setStep(1);

                                window.scrollTo({
                                    top: 0,
                                    behavior:
                                        'smooth',
                                });
                            }}
                            className="gap-2 !h-[40px] !text-[12px] lg:!h-[48px] lg:!text-[15px]"
                        >
                            <ArrowLeft
                                size={16}
                                strokeWidth={1.8}
                            />

                            Back
                        </SecondaryButton>
                    </div>
                </form>
            )}

            <Footer />
        </GuestLayout>
    );
}

function RegistrationStepper({
    step,
}: {
    step: Step;
}) {
    return (
        <div className="auth-register-stepper mb-5 mt-6">
            <div className="grid grid-cols-[auto_1fr_auto] items-start">
                <StepperItem
                    number={1}
                    label="Personal Info"
                    completed={step === 2}
                    active={step === 1}
                />

                <div
                    className={`mt-[14px] h-[2px] ${
                        step === 2
                            ? 'bg-[#3947cf]'
                            : 'bg-[#e1e4eb]'
                    }`}
                />

                <StepperItem
                    number={2}
                    label="Review & Submit"
                    active={step === 2}
                />
            </div>
        </div>
    );
}

function StepperItem({
    number,
    label,
    active = false,
    completed = false,
}: {
    number: number;
    label: string;
    active?: boolean;
    completed?: boolean;
}) {
    return (
        <div className="flex min-w-[70px] flex-col items-center">
            <div
                className={`flex h-[29px] w-[29px] items-center justify-center rounded-full text-[11px] font-bold ${
                    completed
                        ? 'bg-[#16a34a] text-white'
                        : active
                          ? 'bg-[#3947cf] text-white'
                          : 'border border-[#dde1e8] bg-[#eef0f4] text-[#c0c5ce]'
                }`}
            >
                {completed ? (
                    <Check
                        size={15}
                        strokeWidth={2.5}
                    />
                ) : (
                    number
                )}
            </div>

            <span
                className={`mt-1 text-[9px] font-semibold ${
                    completed || active
                        ? 'text-[#3947cf]'
                        : 'text-[#c4c8d0]'
                }`}
            >
                {label}
            </span>
        </div>
    );
}

function RegistrationAlert({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div
            role="alert"
            className="mb-4 flex min-h-[46px] items-center gap-[10px] rounded-[10px] border border-[#ff9393] bg-[#fff0f0] px-[14px] text-[13px] text-[#ef3434]"
        >
            <AlertTriangle
                size={18}
                strokeWidth={1.8}
                className="shrink-0"
            />

            <span>{children}</span>
        </div>
    );
}

function PersonalPictureField({
    preview,
    error,
    onChange,
}: {
    preview: string | null;
    error?: string;
    onChange: (
        event: ChangeEvent<HTMLInputElement>,
    ) => void;
}) {
    return (
        <div>
            <div className="flex items-center gap-4">
                <div className="flex h-[65px] w-[65px] shrink-0 items-center justify-center overflow-hidden rounded-full border border-dashed border-[#d7dbe3] text-[#cbd0d8]">
                    {preview ? (
                        <img
                            src={preview}
                            alt="Personal photo preview"
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <UserRound
                            size={24}
                            strokeWidth={1.7}
                        />
                    )}
                </div>

                <div>
                    <label className="inline-flex h-[34px] cursor-pointer items-center justify-center gap-2 rounded-[7px] border border-[#3947cf] px-4 text-[11px] font-bold text-[#3947cf] transition hover:bg-[#3947cf]/5">
                        <Camera
                            size={14}
                            strokeWidth={2}
                        />

                        UPLOAD PHOTO

                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            onChange={onChange}
                        />
                    </label>

                    <p className="mt-1.5 text-[9px] text-[#c4c8d0]">
                        Optional • JPG, PNG or WEBP up to 5 MB
                    </p>
                </div>
            </div>

            <InputError message={error} />
        </div>
    );
}

function ApplicantSummary({
    name,
    email,
}: {
    name: string;
    email: string;
}) {
    return (
        <div className="flex min-h-[61px] items-center gap-3 rounded-[10px] border border-[#cfd8ff] bg-[#eef3ff] px-3.5 py-2.5">
            <div className="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-full bg-[#d9e3ff] text-[#3947cf]">
                <UserRound
                    size={18}
                    strokeWidth={1.8}
                />
            </div>

            <div className="min-w-0 flex-1">
                <p className="truncate text-[12px] font-semibold text-[#3947cf]">
                    {name}
                </p>

                <p className="mt-0.5 truncate text-[9px] text-[#6979d7]">
                    {email}
                </p>
            </div>

            <span className="max-w-[135px] rounded-full bg-[#d9fbe2] px-2.5 py-1 text-center text-[9px] font-semibold leading-[13px] text-[#16a34a]">
                Personal info complete — final step!
            </span>
        </div>
    );
}

function SelectInput({
    hasError = false,
    className = '',
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & {
    hasError?: boolean;
}) {
    return (
        <select
            {...props}
            className={
                `h-[40px] w-full rounded-[11px] border px-[12px] ` +
                `text-[13px] text-[#252832] outline-none transition duration-200 ` +
                `focus:border-[#4a53d4] focus:ring-2 focus:ring-[#4a53d4]/10 ` +
                `lg:h-[48px] lg:px-[16px] lg:text-[15px] ` +
                (hasError
                    ? 'border-[#ff5656] bg-[#fffafa] '
                    : 'border-[#dce0e8] bg-white ') +
                className
            }
        >
            {children}
        </select>
    );
}

function SummaryRow({
    label,
    value,
    last = false,
}: {
    label: string;
    value: string;
    last?: boolean;
}) {
    return (
        <div
            className={`grid grid-cols-[58px_1fr] items-start gap-2 text-[11px] ${
                last ? '' : 'mb-3'
            }`}
        >
            <span className="font-semibold tracking-[0.04em] text-[#a6abb5]">
                {label}
            </span>

            <span className="break-all text-[#252832]">
                {value}
            </span>
        </div>
    );
}

function Spinner() {
    return (
        <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" />
    );
}

function Footer() {
    return (
        <p className="auth-register-footer mt-8 hidden border-t border-[#e6e8ee] pt-6 text-[10px] text-[#c5c9d1] lg:block">
            © 2026 LCMS
        </p>
    );
}