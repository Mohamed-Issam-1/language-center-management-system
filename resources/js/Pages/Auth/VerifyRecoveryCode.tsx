import {
  ArrowLeft,
  Mail,
} from "lucide-react";
import {
  Head,
  Link,
} from "@inertiajs/react";

import ApplicationLogo from "@/Components/ApplicationLogo";
import PasswordRecoverySteps from "@/Components/Auth/PasswordRecoverySteps";

import GuestLayout from "@/Layouts/GuestLayout";

export default function VerifyRecoveryCode({
  maskedEmail,
}: {
  maskedEmail: string;
}) {
  return (
    <GuestLayout
      mobileTitle="Enter Verification Code"
      mobileSubtitle="A 5-digit code has been sent to the recovery email linked to your account."
    >
      <Head title="Verify Recovery Code" />

      <div>
        <div className="mb-[26px] hidden lg:block">
          <ApplicationLogo
            variant="blue"
            className="h-auto w-[205px]"
          />
        </div>

        <h1 className="mt-4 text-[28px] font-bold text-[#252832] lg:text-[32px]">
          Enter Verification Code
        </h1>

        <p className="mt-[8px] text-[12px] leading-[19px] text-[#adb2be] lg:text-[14px]">
          A 5-digit code has been sent to the
          recovery email linked to your account.
        </p>

        <PasswordRecoverySteps
          currentStep={2}
          completedThrough={1}
        />

        <div className="flex gap-[11px] rounded-[9px] border border-[#7ce2a1] bg-[#dcfbe7] px-[14px] py-[13px] text-[#137c40]">
          <div className="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-white">
            <Mail
              size={17}
              strokeWidth={1.8}
            />
          </div>

          <div>
            <p className="text-[12px] font-bold">
              Code sent successfully
            </p>

            <p className="mt-[2px] text-[11px]">
              We&apos;ve sent a 5-digit code to{" "}
              <strong>
                {maskedEmail}
              </strong>
            </p>
          </div>
        </div>

        <p className="mt-[24px] text-center text-[12px] text-[#adb2be]">
          Verification input will be connected
          in the next checkpoint.
        </p>

        <Link
          href={route("password.request")}
          className="mt-[20px] flex h-[40px] w-full items-center justify-center rounded-[10px] border border-[#dce1ea] text-[12px] font-medium text-[#6f7682] lg:h-[48px] lg:text-[14px]"
        >
          <ArrowLeft
            size={16}
            className="mr-2"
          />
          Back
        </Link>
      </div>
    </GuestLayout>
  );
}