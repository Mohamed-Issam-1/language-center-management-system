import { ArrowRight, TriangleAlert } from "lucide-react";

import { FormEventHandler, useState } from "react";

import Checkbox from "@/Components/Checkbox";
import InputError from "@/Components/InputError";
import InputLabel from "@/Components/InputLabel";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import PasswordInput from "@/Components/Auth/PasswordInput";
import ApplicationLogo from "@/Components/ApplicationLogo";
import LanguageToggle from "@/Components/Auth/LanguageToggle";

import GuestLayout from "@/Layouts/GuestLayout";

import { Head, Link, useForm } from "@inertiajs/react";

export default function Login({
  status,
  canResetPassword = true,
}: {
  status?: string;
  canResetPassword?: boolean;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    login_identifier: "",
    password: "",
    remember: false,
  });

  const [clientError, setClientError] = useState<string | null>(null);

  const submit: FormEventHandler = (e) => {
    e.preventDefault();

    setClientError(null);

    if (!data.login_identifier.trim()) {
      setClientError("Please enter your email address.");
      return;
    }

    if (!data.password) {
      setClientError("Please enter your password.");
      return;
    }

    post(route("login"), {
      onFinish: () => reset("password"),
    });
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
            <h1 className="text-[32px] lg:text-[42px] m-4 font-bold leading-tight tracking-[-0.04em] text-[#22252d]">
              Sign In
            </h1>

            <p className="mt-[10px] text-[13px] lg:text-[20px] leading-[27px] text-[#adb2be]">
              Enter your credentials to access your account.
            </p>
          </div>

          {status && (
            <div className="mt-7 rounded-[10px] border border-[#a4dfbc] bg-[#f0fbf4] px-4 py-3 text-[16px] text-[#17984c]">
              {status}
            </div>
          )}

          {(clientError || errors.login_identifier) && (
            <div className="mt-7 flex min-h-[52px] items-center gap-3 rounded-[10px] border border-[#ff9d9d] bg-[#fff0f0] px-4 text-[16px] text-[#ef3434]">
              <TriangleAlert size={20} strokeWidth={1.8} className="shrink-0" />

              <span>{clientError || errors.login_identifier}</span>
            </div>
          )}

          <form onSubmit={submit} className="mt-[20px] lg:mt-[34px]">
            {/* Email */}
            <div>
              <InputLabel htmlFor="login_identifier" value="Email Address" />

              <TextInput
                id="login_identifier"
                type="text"
                name="login_identifier"
                value={data.login_identifier}
                placeholder="name@center.com"
                autoComplete="username"
                isFocused
                onChange={(e) => setData("login_identifier", e.target.value)}
              />

              {data.login_identifier && (
                <InputError message={errors.login_identifier} />
              )}
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
                onChange={(e) => setData("password", e.target.value)}
              />

              <InputError message={errors.password} />
            </div>

            {/* Remember + forgot */}
            <div className="mt-[10px] lg:mt-[16px] flex items-center justify-between">
              <label className="flex cursor-pointer items-center gap-[10px]">
                <Checkbox
                  name="remember"
                  checked={data.remember}
                  onChange={(e) => setData("remember", e.target.checked)}
                />

                <span className="text-[12px] lg:text-[18px] text-[#858b97]">
                  Remember me
                </span>
              </label>

              {canResetPassword && (
                <Link
                  href={route("password.request")}
                  className="text-[12px] lg:text-[18px] font-semibold text-[#3842c9] transition hover:text-[#252fac]"
                >
                  Forgot password?
                </Link>
              )}
            </div>

            {/* Button */}
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
                    <ArrowRight size={20} strokeWidth={2} className="ml-2" />
                  </>
                )}
              </PrimaryButton>
            </div>

            {/* Register */}
            <p className="mt-[11px] lg:mt-[16px] text-center text-[12px] lg:text-[18px] text-[#adb2bd]">
              Don't have an account?{" "}
              <Link
                href={route("register")}
                className="font-semibold text-[#3842c9] transition hover:text-[#252fac]"
              >
                Create one
              </Link>
            </p>
          </form>
        </div>

        {/* Footer */}
        <footer className="absolute bottom-5 left-0 right-0 flex justify-center lg:static lg:mt-[40px] lg:justify-between lg:border-t lg:border-[#e6e9f1] lg:pt-[24px] lg:pb-[47px]">
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
