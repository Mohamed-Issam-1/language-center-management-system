import {
  FormEventHandler,
  useEffect,
  useRef,
  useState,
  type ClipboardEvent,
  type KeyboardEvent,
} from "react";

import {
  ArrowLeft,
  ArrowRight,
  CircleAlert,
  Clock3,
  Mail,
  TriangleAlert,
} from "lucide-react";

import {
  Head,
  Link,
  router,
  useForm,
} from "@inertiajs/react";

import ApplicationLogo from "@/Components/ApplicationLogo";
import PrimaryButton from "@/Components/PrimaryButton";
import PasswordRecoverySteps from "@/Components/Auth/PasswordRecoverySteps";

import GuestLayout from "@/Layouts/GuestLayout";

const CODE_LENGTH = 5;

export default function VerifyRecoveryCode({
  maskedEmail,
}: {
  maskedEmail: string;
}) {
  const {
    data,
    setData,
    post,
    processing,
    errors,
    clearErrors,
  } = useForm({
    code: "",
  });

  const [digits, setDigits] = useState<string[]>(
    Array(CODE_LENGTH).fill(""),
  );

  const [submitted, setSubmitted] = useState(false);

  const [resendSeconds, setResendSeconds] = useState(60);

  const [resending, setResending] = useState(false);

  const inputRefs = useRef<Array<HTMLInputElement | null>>([]);

  useEffect(() => {
    if (resendSeconds <= 0) {
      return;
    }

    const interval = window.setInterval(() => {
      setResendSeconds((current) => Math.max(0, current - 1));
    }, 1000);

    return () => window.clearInterval(interval);
  }, [resendSeconds]);

  const clientValidationError =
    submitted && data.code.length !== CODE_LENGTH
      ? "Please enter the complete 5-digit verification code."
      : null;

  const displayedError =
    errors.code || clientValidationError;

  const hasCodeError = Boolean(displayedError);

  const updateDigits = (nextDigits: string[]) => {
    setDigits(nextDigits);
    setData("code", nextDigits.join(""));
    clearErrors("code");
  };

  const handleChange = (
    index: number,
    value: string,
  ) => {
    const digit = value
      .replace(/\D/g, "")
      .slice(-1);

    const nextDigits = [...digits];
    nextDigits[index] = digit;

    updateDigits(nextDigits);

    if (digit && index < CODE_LENGTH - 1) {
      inputRefs.current[index + 1]?.focus();
    }
  };

  const handleKeyDown = (
    index: number,
    event: KeyboardEvent<HTMLInputElement>,
  ) => {
    if (
      event.key === "Backspace" &&
      !digits[index] &&
      index > 0
    ) {
      inputRefs.current[index - 1]?.focus();
    }

    if (event.key === "ArrowLeft" && index > 0) {
      inputRefs.current[index - 1]?.focus();
    }

    if (
      event.key === "ArrowRight" &&
      index < CODE_LENGTH - 1
    ) {
      inputRefs.current[index + 1]?.focus();
    }
  };

  const handlePaste = (
    event: ClipboardEvent<HTMLInputElement>,
  ) => {
    event.preventDefault();

    const pastedCode = event.clipboardData
      .getData("text")
      .replace(/\D/g, "")
      .slice(0, CODE_LENGTH);

    if (!pastedCode) {
      return;
    }

    const nextDigits = Array(CODE_LENGTH).fill("");

    pastedCode.split("").forEach((digit, index) => {
      nextDigits[index] = digit;
    });

    updateDigits(nextDigits);

    inputRefs.current[
      Math.min(pastedCode.length, CODE_LENGTH - 1)
    ]?.focus();
  };

  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    setSubmitted(true);

    if (data.code.length !== CODE_LENGTH) {
      return;
    }

    clearErrors();

    post(
      route("password.recovery.verify.submit"),
      {
        preserveScroll: true,
      },
    );
  };

  const resendCode = () => {
    if (resendSeconds > 0 || resending) {
      return;
    }

    setResending(true);

    router.post(
      route("password.recovery.resend"),
      {},
      {
        preserveScroll: true,

        onSuccess: () => {
          setDigits(Array(CODE_LENGTH).fill(""));
          setData("code", "");
          setSubmitted(false);
          setResendSeconds(60);

          inputRefs.current[0]?.focus();
        },

        onFinish: () => {
          setResending(false);
        },
      },
    );
  };

  return (
    <GuestLayout
      mobileTitle="Enter Verification Code"
      mobileSubtitle="A 5-digit code has been sent to the recovery email linked to your account."
    >
      <Head title="Verify Recovery Code" />

      <div>
        {/* Desktop logo */}
        <div className="auth-recovery-logo mb-[26px] hidden lg:block">
          <ApplicationLogo
            variant="blue"
            className="auth-recovery-logo-image h-auto w-[205px]"
          />
        </div>

        {/* Heading */}
        <div>
          <h1 className="auth-recovery-title mt-4 text-[28px] font-bold leading-tight tracking-[-0.03em] text-[#252832] lg:text-[32px]">
            Enter Verification Code
          </h1>

          <p className="auth-recovery-subtitle mt-[8px] max-w-[440px] text-[12px] leading-[19px] text-[#adb2be] lg:text-[14px] lg:leading-[22px]">
            A 5-digit code has been sent to the recovery email linked to your
            account.
          </p>
        </div>

        <div className="auth-recovery-steps">
          <PasswordRecoverySteps
                    currentStep={2}
                    completedThrough={1}
                  />
        </div>

        {/* Validation error */}
        {displayedError && (
          <div
            role="alert"
            className="mb-[16px] flex min-h-[46px] items-center gap-[10px] rounded-[9px] border border-[#ff9393] bg-[#fff0f0] px-[14px] text-[12px] text-[#ef3434] lg:text-[13px]"
          >
            <TriangleAlert
              size={17}
              strokeWidth={1.8}
              className="shrink-0"
            />

            <span>{displayedError}</span>
          </div>
        )}

        {/* Email success */}
        <div className="flex gap-[11px] rounded-[9px] border border-[#7ce2a1] bg-[#dcfbe7] px-[14px] py-[13px] text-[#137c40]">
          <div className="flex h-[36px] w-[36px] shrink-0 items-center justify-center rounded-full bg-white">
            <Mail
              size={18}
              strokeWidth={1.8}
            />
          </div>

          <div>
            <p className="text-[12px] font-bold">
              Code sent successfully
            </p>

            <p className="mt-[2px] text-[11px] leading-[17px]">
              We&apos;ve sent a 5-digit code to{" "}
              <strong>{maskedEmail}</strong>
            </p>
          </div>
        </div>

        <form
          onSubmit={submit}
          className="auth-recovery-form mt-[22px]"
        >
          {/* Verification code */}
          <div>
            <p className="mb-[11px] text-[11px] font-bold uppercase tracking-[0.025em] text-[#6c7381]">
              Verification Code
            </p>

            <div className="flex justify-center gap-[10px]">
              {digits.map((digit, index) => (
                <input
                  key={index}
                  ref={(element) => {
                    inputRefs.current[index] = element;
                  }}
                  type="text"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  maxLength={1}
                  value={digit}
                  autoComplete={
                    index === 0
                      ? "one-time-code"
                      : "off"
                  }
                  aria-label={`Verification digit ${index + 1}`}
                  onChange={(e) =>
                    handleChange(index, e.target.value)
                  }
                  onKeyDown={(e) =>
                    handleKeyDown(index, e)
                  }
                  onPaste={handlePaste}
                  className={[
                    "h-[54px] w-[48px] rounded-[9px] border bg-white text-center text-[20px] font-bold text-[#3f46d3] outline-none transition lg:h-[58px] lg:w-[52px]",
                    hasCodeError
                      ? "border-[#ff5656] bg-[#fffafa] focus:border-[#ff5656]"
                      : digit
                        ? "border-2 border-[#3f46d3]"
                        : "border-[#d7dce6] focus:border-[#3f46d3]",
                  ].join(" ")}
                />
              ))}
            </div>

            <p className="mt-[8px] text-center text-[10px] text-[#c2c6cf] lg:text-[11px]">
              Enter the 5-digit code from your email
            </p>
          </div>

          {/* Expiration */}
          <div className="auth-recovery-warning mt-[20px] flex gap-[10px] rounded-[9px] border border-[#f1ce61] bg-[#fff4c9] px-[14px] py-[13px] text-[#b26b08]">
            <Clock3
              size={17}
              strokeWidth={1.8}
              className="mt-[1px] shrink-0"
            />

            <p className="text-[11px] leading-[18px] lg:text-[12px]">
              This code expires in{" "}
              <strong>30 minutes</strong>{" "}
              and can only be used once. Do not share it with anyone.
            </p>
          </div>

          {/* Information */}
          <div className="auth-recovery-secondary-info mt-[10px] flex gap-[10px] rounded-[9px] border border-[#b8c7f3] bg-[#edf2ff] px-[14px] py-[13px] text-[#354ac9]">
            <CircleAlert
              size={17}
              strokeWidth={1.8}
              className="mt-[1px] shrink-0"
            />

            <p className="text-[11px] leading-[18px] lg:text-[12px]">
              Check your spam or junk folder if you don&apos;t see the email.
              The code is 5 digits and appears in the subject line.
            </p>
          </div>

          {/* Verify */}
          <div className="auth-recovery-submit mt-[18px]">
            <PrimaryButton disabled={processing}>
              {processing ? (
                <>
                  <Spinner />
                  Verifying...
                </>
              ) : (
                <>
                  Verify Code

                  <ArrowRight
                    size={18}
                    strokeWidth={2}
                    className="ml-2"
                  />
                </>
              )}
            </PrimaryButton>
          </div>

          {/* Resend */}
          <div className="auth-recovery-resend mt-[11px] text-center text-[11px] lg:text-[12px]">
            {resendSeconds > 0 ? (
              <span className="text-[#c1c5ce]">
                Resend code in{" "}
                <strong className="text-[#3f46d3]">
                  {resendSeconds}s
                </strong>
              </span>
            ) : (
              <button
                type="button"
                onClick={resendCode}
                disabled={resending}
                className="font-semibold text-[#3f46d3] transition hover:text-[#3038bd]"
              >
                {resending
                  ? "Resending..."
                  : "Didn't receive the code? Resend"}
              </button>
            )}
          </div>

          {/* Back */}
          <Link
            href={route("password.request")}
            className="mt-[14px] flex h-[40px] w-full items-center justify-center rounded-[10px] border border-[#dce1ea] text-[12px] font-medium text-[#6f7682] transition hover:bg-white lg:h-[48px] lg:text-[14px]"
          >
            <ArrowLeft
              size={16}
              strokeWidth={1.8}
              className="mr-2"
            />

            Back
          </Link>
        </form>

        <Footer />
      </div>
    </GuestLayout>
  );
}

function Footer() {
  return (
    <footer className="auth-recovery-footer mt-[27px] border-t border-[#e5e8ef] pt-[20px]">
      <span className="text-[10px] text-[#c2c6cf] lg:text-[11px]">
        © 2026 LCMS
      </span>
    </footer>
  );
}

function Spinner() {
  return (
    <span className="mr-2 h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
  );
}