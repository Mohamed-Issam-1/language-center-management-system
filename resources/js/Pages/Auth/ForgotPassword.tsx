import { FormEventHandler, useState } from "react";
import {
  ArrowLeft,
  ArrowRight,
  CircleAlert,
  LockKeyhole,
  Mail,
  TriangleAlert,
} from "lucide-react";
import { Head, Link, useForm } from "@inertiajs/react";

import InputLabel from "@/Components/InputLabel";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import PasswordRecoverySteps from "@/Components/Auth/PasswordRecoverySteps";

import GuestLayout from "@/Layouts/GuestLayout";
import ApplicationLogo from "@/Components/ApplicationLogo";

export default function ForgotPassword({ status }: { status?: string }) {
  const { data, setData, post, processing, errors, clearErrors } = useForm({
    email: "",
  });

  const [submitted, setSubmitted] = useState(false);
  const [showSuccess, setShowSuccess] = useState(Boolean(status));

  const getRequestKey = (email: string) =>
    `lcms-password-recovery:${email.trim().toLowerCase()}`;
  const [duplicateError, setDuplicateError] = useState<string | null>(null);

  const emailHasError =
    Boolean(errors.email) || (submitted && !data.email.trim());

  const clientValidationError =
    submitted && !data.email.trim() ? "Please enter your email address." : null;

  const displayedError =
    duplicateError || errors.email || clientValidationError;

  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    setSubmitted(true);
    setDuplicateError(null);

    const email = data.email.trim().toLowerCase();

    if (!email) {
      return;
    }

    clearErrors();

    const requestKey = getRequestKey(email);

    const alreadyRequested = localStorage.getItem(requestKey);

    if (alreadyRequested) {
      setDuplicateError(
        "A password recovery request has already been submitted for this email.",
      );

      return;
    }

    localStorage.setItem(
      requestKey,
      JSON.stringify({
        email,
        requestedAt: new Date().toISOString(),
      }),
    );

    // For demonstration purposes, we can simulate a successful submission without actually sending a request.
    setShowSuccess(true);

    // post(route("password.email"), {
    //   preserveScroll: true,

    //   onSuccess: () => {
    //     setShowSuccess(true);
    //   },
    // });
  };

  const submitAnotherRequest = () => {
    setShowSuccess(false);
    setSubmitted(false);
    clearErrors();
  };

  return (
    <GuestLayout>
      <Head title="Password Recovery" />

      <div>
        {/* Desktop logo */}
        <div className="mb-[26px] hidden lg:block">
          <ApplicationLogo variant="blue" className="h-auto w-[205px]" />
        </div>

        {/* Heading */}
        <div>
          <h1 className="text-[28px] m-4 font-bold leading-tight tracking-[-0.03em] text-[#252832] lg:text-[36px]">
            Password Recovery
          </h1>

          <p className="mt-[8px] max-w-[430px] text-[12px] leading-[19px] text-[#adb2be] lg:text-[16px] lg:leading-[24px]">
            Enter your registered email to submit a recovery request to your
            administrator.
          </p>
        </div>

        <PasswordRecoverySteps
          currentStep={showSuccess ? 2 : 1}
          completedThrough={showSuccess ? 2 : 0}
        />

        {showSuccess ? (
          <SuccessState
            email={data.email}
            onSubmitAnother={submitAnotherRequest}
          />
        ) : (
          <>
            {/* Error */}
            {displayedError && (
              <div
                role="alert"
                className="mb-[18px] flex min-h-[46px] items-center gap-[10px] rounded-[9px] border border-[#ff9393] bg-[#fff0f0] px-[14px] text-[12px] text-[#ef3434] lg:min-h-[52px] lg:text-[14px]"
              >
                <TriangleAlert
                  size={18}
                  strokeWidth={1.8}
                  className="shrink-0"
                />

                <span>{displayedError}</span>
              </div>
            )}

            {/* Info box */}
            <div className="flex gap-[11px] rounded-[9px] border border-[#b8c7f3] bg-[#edf2ff] px-[14px] py-[13px] text-[#354ac9]">
              <LockKeyhole
                size={17}
                strokeWidth={1.8}
                className="mt-[1px] shrink-0"
              />

              <p className="text-[11px] leading-[18px] lg:text-[13px] lg:leading-[21px]">
                Password recovery is handled by your center administrator. Enter
                your email and your admin will issue a temporary password for
                you to use at first login.
              </p>
            </div>

            <form onSubmit={submit} className="mt-[22px]">
              <div>
                <InputLabel htmlFor="email" value="Registered Email Address" />

                <TextInput
                  id="email"
                  type="email"
                  name="email"
                  value={data.email}
                  placeholder="name@center.com"
                  autoComplete="email"
                  hasError={emailHasError}
                  aria-invalid={emailHasError}
                  onChange={(e) => {
                    setData("email", e.target.value);
                    clearErrors("email");
                  }}
                />

                <p className="mt-[7px] text-[10px] text-[#bdc1ca] lg:text-[12px]">
                  Use the email associated with your LCMS account.
                </p>
              </div>

              <div className="mt-[19px]">
                <PrimaryButton disabled={processing}>
                  {processing ? (
                    <>
                      <Spinner />
                      Sending...
                    </>
                  ) : (
                    <>
                      Send Recovery Request
                      <ArrowRight size={18} strokeWidth={2} className="ml-2" />
                    </>
                  )}
                </PrimaryButton>
              </div>

              <Link
                href={route("login")}
                className="mt-[13px] flex h-[40px] w-full items-center justify-center rounded-[10px] border border-[#dce1ea] text-[12px] font-medium text-[#6f7682] transition hover:bg-white lg:h-[50px] lg:text-[15px]"
              >
                <ArrowLeft size={16} strokeWidth={1.8} className="mr-2" />
                Back to Login
              </Link>
            </form>
          </>
        )}

        {!showSuccess && <Footer />}
      </div>
    </GuestLayout>
  );
}

function SuccessState({
  email,
  onSubmitAnother,
}: {
  email: string;
  onSubmitAnother: () => void;
}) {
  return (
    <div>
      {/* Main success card */}
      <div className="rounded-[12px] border border-[#7ce2a1] bg-[#dcfbe7] px-[20px] py-[24px] text-center lg:py-[27px]">
        <div className="mx-auto flex h-[56px] w-[56px] items-center justify-center rounded-full bg-white shadow-[0_6px_14px_rgba(44,166,95,0.14)]">
          <Mail size={25} strokeWidth={2} className="text-[#16a357]" />
        </div>

        <h2 className="mt-[15px] text-[18px] font-bold text-[#149447] lg:text-[21px]">
          Request Sent Successfully
        </h2>

        <p className="mx-auto mt-[8px] max-w-[350px] text-[11px] leading-[18px] text-[#37744e] lg:text-[13px] lg:leading-[20px]">
          A password reset request has been submitted for
          <br />
          <strong className="font-bold text-[#235b39]">{email}</strong>
        </p>
      </div>

      {/* What happens next */}
      <div className="mt-[16px] rounded-[11px] border border-[#e1e4eb] bg-white px-[18px] py-[17px]">
        <h3 className="text-[10px] font-bold uppercase tracking-[0.04em] text-[#737986] lg:text-[12px]">
          What happens next?
        </h3>

        <div className="mt-[13px] space-y-[12px]">
          <NextStep
            number={1}
            text="Your administrator will review the request."
          />

          <NextStep
            number={2}
            text="A temporary password will be issued to your account."
          />

          <NextStep number={3} text="You must change it on your first login." />
        </div>
      </div>

      {/* Note */}
      <div className="mt-[16px] flex gap-[10px] rounded-[9px] bg-[#eaf0fc] px-[15px] py-[13px] text-[#3751c8]">
        <CircleAlert
          size={16}
          strokeWidth={1.8}
          className="mt-[1px] shrink-0"
        />

        <p className="text-[10px] leading-[16px] lg:text-[12px] lg:leading-[19px]">
          <strong>Note:</strong> Self-service email recovery is not available in
          this version. If you do not hear from your administrator within 24
          hours, please contact them directly.
        </p>
      </div>

      <button
        type="button"
        onClick={onSubmitAnother}
        className="mt-[17px] flex h-[40px] w-full items-center justify-center rounded-[9px] bg-[#edf0f9] text-[12px] font-semibold text-[#3647cf] transition hover:bg-[#e4e8f5] lg:h-[48px] lg:text-[14px]"
      >
        Submit Another Request
      </button>

      <Link
        href={route("login")}
        className="mt-[10px] flex h-[40px] w-full items-center justify-center rounded-[9px] bg-[#3f46d3] text-[12px] font-semibold text-white transition hover:bg-[#353cc3] lg:h-[48px] lg:text-[14px]"
      >
        Back to Login
      </Link>
    </div>
  );
}

function NextStep({ number, text }: { number: number; text: string }) {
  return (
    <div className="flex items-center gap-[11px]">
      <span className="flex h-[20px] w-[20px] shrink-0 items-center justify-center rounded-full bg-[#edf1ff] text-[10px] font-bold text-[#4352d4]">
        {number}
      </span>

      <span className="text-[11px] text-[#5f6672] lg:text-[13px]">{text}</span>
    </div>
  );
}

function Footer() {
  return (
    <footer className="mt-[27px] border-t border-[#e5e8ef] pt-[20px]">
      <span className="text-[10px] text-[#c2c6cf] lg:text-[12px]">
        © 2026 LCMS · Taqat University
      </span>
    </footer>
  );
}

function Spinner() {
  return (
    <span className="mr-2 h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
  );
}
