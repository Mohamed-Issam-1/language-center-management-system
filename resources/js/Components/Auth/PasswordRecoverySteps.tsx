import { Check } from "lucide-react";

type Step = 1 | 2 | 3;

interface PasswordRecoveryStepsProps {
  currentStep: Step;
  completedThrough?: 0 | Step;
  finalComplete?: boolean;
}

const steps = [
  {
    number: 1 as Step,
    label: "Enter Username",
  },
  {
    number: 2 as Step,
    label: "Verify Code",
  },
  {
    number: 3 as Step,
    label: "New Password",
  },
];

export default function PasswordRecoverySteps({
  currentStep,
  completedThrough = 0,
  finalComplete = false,
}: PasswordRecoveryStepsProps) {
  const connectorActive = (afterStep: Step) => {
    return afterStep < currentStep || finalComplete;
  };

  return (
    <div className="mt-[26px]">
      <div className="grid grid-cols-[auto_1fr_auto_1fr_auto] items-start">
        {steps.map((step, index) => {
          const isCompleted = step.number <= completedThrough;
          const isCurrent = step.number === currentStep;

          return (
            <div
              key={step.number}
              className="contents"
            >
              <div className="relative flex flex-col items-center">
                <div
                  className={[
                    "flex h-[24px] w-[24px] items-center justify-center rounded-full text-[10px] font-bold transition",
                    isCompleted
                      ? "bg-[#3f46d3] text-white"
                      : isCurrent
                        ? finalComplete || currentStep === 1
                          ? "bg-[#3f46d3] text-white"
                          : "border-2 border-[#3f46d3] bg-transparent text-[#3f46d3]"
                        : "border border-[#dce1e9] bg-[#f3f5f8] text-[#c1c6ce]",
                  ].join(" ")}
                >
                  {isCompleted ? (
                    <Check size={14} strokeWidth={2.6} />
                  ) : (
                    step.number
                  )}
                </div>

                <span
                  className={[
                    "absolute top-[31px] w-[92px] text-center text-[9px] font-semibold",
                    isCompleted || isCurrent
                      ? "text-[#3f46d3]"
                      : "text-[#c0c5cf]",
                  ].join(" ")}
                >
                  {step.label}
                </span>
              </div>

              {index < steps.length - 1 && (
                <div
                  className={[
                    "mt-[11px] h-[2px] w-full",
                    connectorActive(step.number)
                      ? "bg-[#5962e2]"
                      : "bg-[#e2e5eb]",
                  ].join(" ")}
                />
              )}
            </div>
          );
        })}
      </div>

      <div className="h-[28px]" />
    </div>
  );
}