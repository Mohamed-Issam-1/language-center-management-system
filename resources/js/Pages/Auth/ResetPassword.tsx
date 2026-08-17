import { FormEventHandler, useMemo, useState } from "react";
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Circle,
  LockKeyhole,
  X,
} from "lucide-react";
import { Head, Link, useForm } from "@inertiajs/react";

import ApplicationLogo from "@/Components/ApplicationLogo";
import InputLabel from "@/Components/InputLabel";
import PasswordInput from "@/Components/Auth/PasswordInput";
import PasswordRecoverySteps from "@/Components/Auth/PasswordRecoverySteps";
import PrimaryButton from "@/Components/PrimaryButton";

import GuestLayout from "@/Layouts/GuestLayout";

export default function ResetPassword({
  token,
  email,
}: {
  token: string;
  email: string;
}) {
  const {
    data,
    setData,
    post,
    processing,
    errors,
    clearErrors,
  } = useForm({
    token,
    email,
    password: "",
    password_confirmation: "",
  });

  const [showSuccess, setShowSuccess] = useState(false);

  const requirements = useMemo(
    () => ({
      length: data.password.length >= 8,
      uppercase: /[A-Z]/.test(data.password),
      lowercase: /[a-z]/.test(data.password),
      number: /[0-9]/.test(data.password),
      special: /[^A-Za-z0-9]/.test(data.password),
    }),
    [data.password],
  );

  const passedCount =
    Object.values(requirements).filter(Boolean).length;

  const allRequirementsMet =
    passedCount === 5;

  const passwordsMatch =
    Boolean(data.password_confirmation) &&
    data.password === data.password_confirmation;

  const formValid =
    allRequirementsMet && passwordsMatch;

  const strength = getPasswordStrength(
    passedCount,
    data.password.length,
  );

  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    if (!formValid) {
      return;
    }

    clearErrors();

    post(route("password.store"), {
      preserveScroll: true,

      onSuccess: () => {
        setShowSuccess(true);
      },
    });
  };

  return (
    <GuestLayout>
      <Head title="Set New Password" />

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
          <h1 className="text-[28px] font-bold leading-tight tracking-[-0.03em] text-[#252832] lg:text-[36px]">
            Set New Password
          </h1>

          <p className="mt-[8px] max-w-[440px] text-[12px] leading-[19px] text-[#adb2be] lg:text-[16px] lg:leading-[24px]">
            Create a strong password to protect your account. All requirements
            must be met before saving.
          </p>
        </div>

        <PasswordRecoverySteps
          currentStep={3}
          completedThrough={2}
          finalComplete={showSuccess}
        />

        {showSuccess ? (
          <PasswordChangedSuccess />
        ) : (
          <form onSubmit={submit}>
            {/* New password */}
            <div>
              <InputLabel
                htmlFor="password"
                value="New Password"
              />

              <PasswordInput
                id="password"
                name="password"
                value={data.password}
                placeholder="Create a strong password"
                autoComplete="new-password"
                hasError={Boolean(errors.password)}
                aria-invalid={Boolean(errors.password)}
                onChange={(e) => {
                  setData("password", e.target.value);
                  clearErrors("password");
                }}
              />

              {data.password && (
                <PasswordStrength
                  label={strength.label}
                  width={strength.width}
                  state={strength.state}
                />
              )}
            </div>

            {/* Confirm password */}
            <div className="mt-[18px]">
              <InputLabel
                htmlFor="password_confirmation"
                value="Confirm New Password"
              />

              <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                value={data.password_confirmation}
                placeholder="Re-enter your new password"
                autoComplete="new-password"
                hasError={Boolean(errors.password_confirmation)}
                aria-invalid={Boolean(errors.password_confirmation)}
                onChange={(e) => {
                  setData(
                    "password_confirmation",
                    e.target.value,
                  );

                  clearErrors("password_confirmation");
                }}
              />

              {data.password_confirmation && (
                <div
                  className={[
                    "mt-[7px] flex items-center gap-[5px] text-[10px] font-medium lg:text-[12px]",
                    passwordsMatch
                      ? "text-[#16a357]"
                      : "text-[#ef3e45]",
                  ].join(" ")}
                >
                  {passwordsMatch ? (
                    <Check
                      size={13}
                      strokeWidth={2.2}
                    />
                  ) : (
                    <X
                      size={13}
                      strokeWidth={2.2}
                    />
                  )}

                  {passwordsMatch
                    ? "Passwords match"
                    : "Passwords do not match"}
                </div>
              )}
            </div>

            {/* Requirements */}
            <div className="mt-[19px] rounded-[10px] border border-[#e0e4ec] px-[16px] py-[16px]">
              <h3 className="text-[10px] font-bold uppercase tracking-[0.04em] text-[#747b87] lg:text-[12px]">
                Password Requirements
              </h3>

              <div className="mt-[13px] space-y-[9px]">
                <Requirement
                  label="At least 8 characters"
                  passed={requirements.length}
                  active={Boolean(data.password)}
                />

                <Requirement
                  label="At least one uppercase letter (A–Z)"
                  passed={requirements.uppercase}
                  active={Boolean(data.password)}
                />

                <Requirement
                  label="At least one lowercase letter (a–z)"
                  passed={requirements.lowercase}
                  active={Boolean(data.password)}
                />

                <Requirement
                  label="At least one number (0–9)"
                  passed={requirements.number}
                  active={Boolean(data.password)}
                />

                <Requirement
                  label="At least one special character (!@#$...)"
                  passed={requirements.special}
                  active={Boolean(data.password)}
                />
              </div>
            </div>

            {/* Server error */}
            {(errors.password ||
              errors.password_confirmation ||
              errors.email) && (
              <div className="mt-[12px] text-[11px] text-[#ef3e45] lg:text-[13px]">
                {errors.password ||
                  errors.password_confirmation ||
                  errors.email}
              </div>
            )}

            {/* Save */}
            <div className="mt-[20px]">
              <PrimaryButton
                disabled={!formValid || processing}
                className="
                  disabled:bg-[#dfe3e8]
                  disabled:text-[#aeb5bf]
                  disabled:opacity-100
                "
              >
                {processing ? (
                  <>
                    <Spinner />
                    Saving...
                  </>
                ) : (
                  <>
                    Save New Password

                    <ArrowRight
                      size={18}
                      strokeWidth={2}
                      className="ml-2"
                    />
                  </>
                )}
              </PrimaryButton>
            </div>

            <BackToLogin />

            <Footer />
          </form>
        )}
      </div>
    </GuestLayout>
  );
}

function Requirement({
  label,
  passed,
  active,
}: {
  label: string;
  passed: boolean;
  active: boolean;
}) {
  if (!active) {
    return (
      <div className="flex items-center gap-[9px] text-[#bec3cc]">
        <Circle
          size={14}
          strokeWidth={1.5}
        />

        <span className="text-[11px] lg:text-[13px]">
          {label}
        </span>
      </div>
    );
  }

  return (
    <div
      className={[
        "flex items-center gap-[9px]",
        passed
          ? "text-[#16a357]"
          : "text-[#ef3e45]",
      ].join(" ")}
    >
      <span
        className={[
          "flex h-[15px] w-[15px] shrink-0 items-center justify-center rounded-full",
          passed
            ? "bg-[#19ad5b] text-white"
            : "border border-[#ef3e45] text-[#ef3e45]",
        ].join(" ")}
      >
        {passed ? (
          <Check
            size={10}
            strokeWidth={2.6}
          />
        ) : (
          <X
            size={9}
            strokeWidth={2}
          />
        )}
      </span>

      <span className="text-[11px] lg:text-[13px]">
        {label}
      </span>
    </div>
  );
}

function PasswordStrength({
  label,
  width,
  state,
}: {
  label: string;
  width: string;
  state: "weak" | "medium" | "strong";
}) {
  const textClass =
    state === "weak"
      ? "text-[#ef3e45]"
      : state === "medium"
        ? "text-[#d98b18]"
        : "text-[#138943]";

  const barClass =
    state === "weak"
      ? "bg-[#ef3e45]"
      : state === "medium"
        ? "bg-[#d98b18]"
        : "bg-[#1d9850]";

  return (
    <div className="mt-[7px]">
      <div className="flex items-center justify-between text-[9px] lg:text-[11px]">
        <span className="text-[#b2b7c0]">
          Password strength
        </span>

        <span className={`font-semibold ${textClass}`}>
          {label}
        </span>
      </div>

      <div className="mt-[4px] h-[4px] overflow-hidden rounded-full bg-[#e4e7ec]">
        <div
          className={`h-full rounded-full transition-all duration-300 ${barClass}`}
          style={{ width }}
        />
      </div>
    </div>
  );
}

function getPasswordStrength(
  passedCount: number,
  length: number,
): {
  label: string;
  width: string;
  state: "weak" | "medium" | "strong";
} {
  if (!length || passedCount <= 2) {
    return {
      label: "Weak",
      width: "20%",
      state: "weak",
    };
  }

  if (passedCount <= 4) {
    return {
      label: "Medium",
      width: "60%",
      state: "medium",
    };
  }

  return {
    label: "Very Strong",
    width: "100%",
    state: "strong",
  };
}

function PasswordChangedSuccess() {
  return (
    <div>
      <div className="rounded-[12px] border border-[#79dfa0] bg-[#dcfbe7] px-[22px] py-[26px] text-center">
        <div className="mx-auto flex h-[58px] w-[58px] items-center justify-center rounded-full bg-white shadow-[0_6px_14px_rgba(44,166,95,0.14)]">
          <LockKeyhole
            size={27}
            strokeWidth={2}
            className="text-[#16a357]"
          />
        </div>

        <h2 className="mt-[16px] text-[18px] font-bold text-[#169847] lg:text-[21px]">
          Password Changed Successfully
        </h2>

        <p className="mx-auto mt-[8px] max-w-[380px] text-[11px] leading-[18px] text-[#39754f] lg:text-[13px] lg:leading-[20px]">
          Your password has been updated. You can now sign in with your new
          password.
        </p>
      </div>

      <div className="mt-[16px] flex gap-[10px] rounded-[9px] border border-[#c5d2f2] bg-[#edf2ff] px-[15px] py-[13px] text-[#3650c8]">
        <LockKeyhole
          size={17}
          strokeWidth={1.8}
          className="mt-[1px] shrink-0"
        />

        <p className="text-[10px] leading-[16px] lg:text-[12px] lg:leading-[19px]">
          For security, all active sessions have been terminated. Please log in
          again with your new password.
        </p>
      </div>

      <Link
        href={route("login")}
        className="mt-[18px] flex h-[42px] w-full items-center justify-center rounded-[9px] bg-[#3f46d3] text-[12px] font-semibold text-white transition hover:bg-[#353cc3] lg:h-[50px] lg:text-[14px]"
      >
        Go to Login

        <ArrowRight
          size={17}
          strokeWidth={2}
          className="ml-2"
        />
      </Link>
    </div>
  );
}

function BackToLogin() {
  return (
    <Link
      href={route("login")}
      className="mt-[12px] flex h-[40px] w-full items-center justify-center rounded-[9px] border border-[#dce1ea] text-[12px] font-medium text-[#6f7682] transition hover:bg-white lg:h-[48px] lg:text-[14px]"
    >
      <ArrowLeft
        size={15}
        strokeWidth={1.8}
        className="mr-2"
      />

      Back to Login
    </Link>
  );
}

function Footer() {
  return (
    <footer className="mt-[27px] border-t border-[#e5e8ef] pt-[20px]">
      <span className="text-[10px] text-[#c2c6cf] lg:text-[12px]">
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