import { FormEventHandler, useState } from "react";
import {
  ArrowLeft,
  ArrowRight,
  Shield,
  TriangleAlert,
} from "lucide-react";
import {
  Head,
  Link,
  useForm,
} from "@inertiajs/react";

import ApplicationLogo from "@/Components/ApplicationLogo";
import InputLabel from "@/Components/InputLabel";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import PasswordRecoverySteps from "@/Components/Auth/PasswordRecoverySteps";

import GuestLayout from "@/Layouts/GuestLayout";

export default function ForgotPassword() {
  const {
    data,
    setData,
    post,
    processing,
    errors,
    clearErrors,
  } = useForm({
    account_login_identifier: "",
  });

  const [submitted, setSubmitted] =
    useState(false);

  const usernameHasError =
    submitted &&
    !data.account_login_identifier.trim();

  const clientValidationError =
    usernameHasError
      ? "Username is required."
      : null;

  const displayedError =
    errors.account_login_identifier ||
    clientValidationError;

  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    setSubmitted(true);

    if (
      !data.account_login_identifier.trim()
    ) {
      return;
    }

    clearErrors();

    post(
      route("password.recovery.send"),
      {
        preserveScroll: true,
      }
    );
  };

  return (
    <GuestLayout
      mobileTitle="Password Recovery"
      mobileSubtitle="Enter your username to receive a verification code."
    >
      <Head title="Password Recovery" />

      <div>
        {/* Desktop logo */}
        <div className="mb-[26px] hidden lg:block">
          <ApplicationLogo
            variant="blue"
            className="h-auto w-[205px]"
          />
        </div>

        {/* Heading */}
        <div>
          <h1 className="mt-4 text-[28px] font-bold leading-tight tracking-[-0.03em] text-[#252832] lg:text-[32px]">
            Password Recovery
          </h1>

          <p className="mt-[8px] text-[12px] leading-[19px] text-[#adb2be] lg:text-[14px] lg:leading-[22px]">
            Enter your username to receive a
            verification code.
          </p>
        </div>

        <PasswordRecoverySteps
          currentStep={1}
        />

        {displayedError && (
          <div
            role="alert"
            className="mb-[18px] flex min-h-[46px] items-center gap-[10px] rounded-[9px] border border-[#ff9393] bg-[#fff0f0] px-[14px] text-[12px] text-[#ef3434] lg:text-[13px]"
          >
            <TriangleAlert
              size={17}
              strokeWidth={1.8}
              className="shrink-0"
            />

            <span>{displayedError}</span>
          </div>
        )}

        {/* Info */}
        <div className="flex gap-[11px] rounded-[9px] border border-[#b8c7f3] bg-[#edf2ff] px-[14px] py-[13px] text-[#354ac9]">
          <Shield
            size={17}
            strokeWidth={1.8}
            className="mt-[1px] shrink-0"
          />

          <p className="text-[11px] leading-[18px] lg:text-[13px] lg:leading-[21px]">
            Enter your username and a{" "}
            <strong>
              5-digit verification code
            </strong>{" "}
            will be sent to the recovery email
            associated with your account.
          </p>
        </div>

        <form
          onSubmit={submit}
          className="mt-[22px]"
        >
          <InputLabel
            htmlFor="account_login_identifier"
            value="Username"
          />

          <TextInput
            id="account_login_identifier"
            type="text"
            name="account_login_identifier"
            value={
              data.account_login_identifier
            }
            placeholder="Enter your username"
            autoComplete="username"
            hasError={usernameHasError}
            aria-invalid={usernameHasError}
            onChange={(e) => {
              setData(
                "account_login_identifier",
                e.target.value
              );

              clearErrors(
                "account_login_identifier"
              );
            }}
          />

          <p className="mt-[7px] text-[10px] text-[#bdc1ca] lg:text-[11px]">
            Use the username assigned to your
            LCMS account.
          </p>

          {/* What happens next */}
          <div className="mt-[20px] rounded-[11px] border border-[#e1e4eb] bg-white px-[18px] py-[17px]">
            <h3 className="text-[10px] font-bold uppercase tracking-[0.04em] text-[#737986] lg:text-[11px]">
              What happens next?
            </h3>

            <div className="mt-[13px] space-y-[12px]">
              <NextStep
                number={1}
                text="We send a 5-digit code to your account's recovery email."
              />

              <NextStep
                number={2}
                text="You enter the code on the next screen to verify your identity."
              />

              <NextStep
                number={3}
                text="You create a new password for your account."
              />
            </div>
          </div>

          <div className="mt-[19px]">
            <PrimaryButton
              disabled={processing}
            >
              {processing ? (
                <>
                  <Spinner />
                  Sending...
                </>
              ) : (
                <>
                  Send Verification Code
                  <ArrowRight
                    size={18}
                    strokeWidth={2}
                    className="ml-2"
                  />
                </>
              )}
            </PrimaryButton>
          </div>

          <Link
            href={route("login")}
            className="mt-[12px] flex h-[40px] w-full items-center justify-center rounded-[10px] border border-[#dce1ea] text-[12px] font-medium text-[#6f7682] transition hover:bg-white lg:h-[48px] lg:text-[14px]"
          >
            <ArrowLeft
              size={16}
              strokeWidth={1.8}
              className="mr-2"
            />

            Back to Login
          </Link>
        </form>

        <Footer />
      </div>
    </GuestLayout>
  );
}

function NextStep({
  number,
  text,
}: {
  number: number;
  text: string;
}) {
  return (
    <div className="flex items-center gap-[11px]">
      <span className="flex h-[20px] w-[20px] shrink-0 items-center justify-center rounded-full bg-[#edf1ff] text-[10px] font-bold text-[#4352d4]">
        {number}
      </span>

      <span className="text-[11px] text-[#5f6672] lg:text-[12px]">
        {text}
      </span>
    </div>
  );
}

function Footer() {
  return (
    <footer className="mt-[27px] border-t border-[#e5e8ef] pt-[20px]">
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