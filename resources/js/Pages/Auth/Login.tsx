import { FormEventHandler, useEffect, useState } from "react";
import { ArrowRight, Check, TriangleAlert } from "lucide-react";
import { Head, Link, router, useForm } from "@inertiajs/react";

import Checkbox from "@/Components/Checkbox";
import InputLabel from "@/Components/InputLabel";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import PasswordInput from "@/Components/Auth/PasswordInput";
import ApplicationLogo from "@/Components/ApplicationLogo";

import GuestLayout from "@/Layouts/GuestLayout";

export default function Login({
  status,
  canResetPassword = true,
  showSuccessInitially = false,
  successRedirectTo,
}: {
  status?: string;
  canResetPassword?: boolean;
  showSuccessInitially?: boolean;
  successRedirectTo?: string;
}) {
  const { data, setData, post, processing, errors, clearErrors } = useForm({
    account_login_identifier: "",
    password: "",
    remember: false,
  });

  const [submitted, setSubmitted] = useState(false);
  const showSuccess = showSuccessInitially;

  useEffect(() => {
    if (!showSuccess || !successRedirectTo) {
      return;
    }

    const timeout = window.setTimeout(() => {
      router.visit(successRedirectTo);
    }, 1200);

    return () => window.clearTimeout(timeout);
  }, [showSuccess, successRedirectTo]);

  const loginHasError = submitted && !data.account_login_identifier.trim();

  const passwordHasError = submitted && !data.password;

  const clientValidationError = submitted
    ? !data.account_login_identifier.trim()
      ? "Please enter your username."
      : !data.password
        ? "Please enter your password."
        : null
    : null;

  // Server errors take priority over client-side validation.

  const displayedError =
    errors.account_login_identifier || errors.password || clientValidationError;
  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    setSubmitted(true);

    if (!data.account_login_identifier.trim() || !data.password) {
      return;
    }

    clearErrors();

    post(route("login"));
  };
  return (
    <GuestLayout>
      <Head title="Sign In" />

      <div className="flex min-h-full flex-1 flex-col">
        <div>
          {/* Desktop logo */}
          <div className="mb-[30px] hidden lg:block">
            <ApplicationLogo variant="blue" className="h-auto w-[225px]" />
          </div>

          {/* Heading */}
          <div>
            <h1 className="mt-4 text-[20px] font-bold leading-tight tracking-[-0.03em] text-[#22252d] lg:text-[32px]">
              Sign In
            </h1>

            <p className="mt-[6px] text-[11px] leading-[18px] text-[#adb2be] lg:mt-[8px] lg:text-[14px] lg:leading-[22px]">
              Enter your credentials to access your account.
            </p>
          </div>

          {/* Success status */}
          {status && (
            <div className="mt-7 rounded-[10px] border border-[#a4dfbc] bg-[#f0fbf4] px-4 py-3 text-[16px] text-[#17984c]">
              {status}
            </div>
          )}

          {showSuccess ? (
            <div className="mt-[58px] flex flex-col items-center text-center lg:mt-[72px]">
              {/* Success icon */}
              <div className="flex h-[58px] w-[58px] items-center justify-center rounded-full bg-[#dff9e9] lg:h-[68px] lg:w-[68px]">
                <Check className="text-[#13a457]" size={38} strokeWidth={2.5} />
              </div>

              {/* Success text */}
              <h2 className="mt-[18px] text-[20px] font-bold text-[#252832] lg:mt-[22px] lg:text-[26px]">
                Welcome back!
              </h2>

              <p className="mt-[8px] text-[13px] text-[#adb2be] lg:text-[18px]">
                Redirecting to your dashboard...
              </p>

              {/* Loading circle */}
              <span className="mt-[22px] h-[34px] w-[34px] animate-spin rounded-full border-2 border-[#dce3ef] border-t-[#bdc8dc]" />
            </div>
          ) : (
            <>
              {/* Error alert */}
              {displayedError && (
                <div
                  role="alert"
                  className="mt-[28px] flex min-h-[46px] items-center gap-[10px] rounded-[10px] border border-[#ff9393] bg-[#fff0f0] px-[14px] text-[13px] text-[#ef3434] lg:mt-[32px] lg:min-h-[54px] lg:px-[16px] lg:text-[16px]"
                >
                  <TriangleAlert
                    size={19}
                    strokeWidth={1.8}
                    className="shrink-0"
                  />

                  <span>{displayedError}</span>
                </div>
              )}

              <form onSubmit={submit} className="mt-[20px] lg:mt-[34px]">
                {/* Username */}
                <div>
                  <InputLabel
                    htmlFor="account_login_identifier"
                    value="USERNAME"
                  />

                  <TextInput
                    id="account_login_identifier"
                    type="text"
                    name="account_login_identifier"
                    value={data.account_login_identifier}
                    placeholder="Enter your username"
                    autoComplete="username"
                    isFocused
                    hasError={loginHasError}
                    aria-invalid={loginHasError}
                    onChange={(e) => {
                      setData("account_login_identifier", e.target.value);
                      clearErrors("account_login_identifier");
                    }}
                  />
                </div>

                {/* Password */}
                <div className="mt-[14px] lg:mt-[22px]">
                  <InputLabel htmlFor="password" value="Password" />

                  <PasswordInput
                    id="password"
                    name="password"
                    value={data.password}
                    placeholder="Enter your password"
                    autoComplete="current-password"
                    hasError={passwordHasError}
                    aria-invalid={passwordHasError}
                    onChange={(e) => {
                      setData("password", e.target.value);
                      clearErrors("password", "account_login_identifier");
                    }}
                  />
                </div>

                {/* Remember + forgot password */}
                <div className="mt-[10px] flex items-center justify-between lg:mt-[16px]">
                  <label className="flex cursor-pointer items-center gap-[10px]">
                    <Checkbox
                      name="remember"
                      checked={data.remember}
                      onChange={(e) => setData("remember", e.target.checked)}
                    />

                    <span className="text-[12px] text-[#858b97] lg:text-[18px]">
                      Remember me
                    </span>
                  </label>

                  {canResetPassword && (
                    <Link
                      href={route("password.request")}
                      className="text-[12px] font-semibold text-[#3842c9] transition hover:text-[#252fac] lg:text-[18px]"
                    >
                      Forgot password?
                    </Link>
                  )}
                </div>

                {/* Sign in button */}
                <div className="mt-[20px] lg:mt-[28px]">
                  <PrimaryButton disabled={processing}>
                    {processing ? (
                      <>
                        <Spinner />
                        Signing in...
                      </>
                    ) : (
                      <>
                        Sign In
                        <ArrowRight
                          size={20}
                          strokeWidth={2}
                          className="ml-2"
                        />
                      </>
                    )}
                  </PrimaryButton>
                </div>

                {/* Register */}
                <p className="mt-[11px] text-center text-[12px] text-[#adb2bd] lg:mt-[16px] lg:text-[18px]">
                  Don&apos;t have an account?{" "}
                  <span className="font-semibold text-[#3842c9]">
                    Create one
                  </span>
                </p>
              </form>
            </>
          )}
        </div>

        {/* Footer */}
        <footer className="absolute bottom-5 left-0 right-0 flex justify-center lg:static lg:mt-[40px] lg:justify-between lg:border-t lg:border-[#e6e9f1] lg:pb-[47px] lg:pt-[24px]">
          <span className="text-[10px] text-[#c2c6cf] lg:text-[16px]">
            © 2026 LCMS
          </span>
        </footer>
      </div>
    </GuestLayout>
  );
}

function Spinner() {
  return (
    <span className="mr-2 h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white" />
  );
}
