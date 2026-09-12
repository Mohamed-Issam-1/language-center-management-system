import { useForm, usePage } from "@inertiajs/react";
import { CircleCheck, KeyRound } from "lucide-react";
import { FormEvent, useState } from "react";

type PasswordForm = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export default function AccountSecurityCard() {
  const page = usePage();

  const demoMode = page.url.startsWith("/demo");

  const [mode, setMode] = useState<"collapsed" | "editing" | "success">(
    "collapsed",
  );

  const { data, setData, put, processing, errors, clearErrors, reset } =
    useForm<PasswordForm>({
      current_password: "",
      password: "",
      password_confirmation: "",
    });

  const openForm = () => {
    clearErrors();
    reset();

    setMode("editing");
  };

  const cancel = () => {
    clearErrors();
    reset();

    setMode("collapsed");
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    /*
     * Demo pages keep the interaction working
     * without changing a real account.
     */
    if (demoMode) {
      clearErrors();
      reset();

      setMode("success");

      return;
    }

    put("/password", {
      preserveScroll: true,

      onSuccess: () => {
        clearErrors();
        reset();

        setMode("success");
      },
    });
  };

  const currentPasswordError = errors.current_password;

  const newPasswordError = errors.password;

  return (
    <section
      className={[
        "profile-card profile-security-card",

        mode === "collapsed" ? "is-collapsed" : "",
      ]
        .filter(Boolean)
        .join(" ")}
    >
      <h2 className="profile-section-title">Account Security</h2>

      {mode === "collapsed" && (
        <div className="profile-security-collapsed">
          <button
            type="button"
            className="profile-change-password-button"
            onClick={openForm}
          >
            <KeyRound size={15} strokeWidth={1.8} />
            Change Password
          </button>
        </div>
      )}

      {mode === "editing" && (
        <form className="profile-security-form is-expanded" onSubmit={submit}>
          <div className="profile-security-inputs">
            <div>
              <input
                type="password"
                value={data.current_password}
                onChange={(event) => {
                  setData("current_password", event.target.value);

                  if (currentPasswordError) {
                    clearErrors("current_password");
                  }
                }}
                className={[
                  "profile-field-input",

                  currentPasswordError ? "profile-field-input-error" : "",
                ]
                  .filter(Boolean)
                  .join(" ")}
                placeholder="Current password"
                autoComplete="current-password"
                autoFocus
              />

              {currentPasswordError && (
                <p className="profile-field-error">{currentPasswordError}</p>
              )}
            </div>

            <div>
              <input
                type="password"
                value={data.password}
                onChange={(event) => {
                  const value = event.target.value;

                  /*
                   * Backend requires the confirmed rule,
                   * while the approved UI contains only
                   * Current Password + New Password.
                   *
                   * Keep confirmation synchronized
                   * internally instead of adding a third
                   * visible field.
                   */
                  setData((current) => ({
                    ...current,

                    password: value,

                    password_confirmation: value,
                  }));

                  if (newPasswordError) {
                    clearErrors("password");
                  }
                }}
                className={[
                  "profile-field-input",

                  newPasswordError ? "profile-field-input-error" : "",
                ]
                  .filter(Boolean)
                  .join(" ")}
                placeholder="New password"
                autoComplete="new-password"
              />

              {newPasswordError && (
                <p className="profile-field-error">{newPasswordError}</p>
              )}
            </div>
          </div>

          <div className="profile-security-actions">
            <button
              type="submit"
              className="profile-save-button"
              disabled={processing}
            >
              {processing ? "Saving..." : "Save"}
            </button>

            <button
              type="button"
              className="profile-cancel-button"
              onClick={cancel}
              disabled={processing}
            >
              Cancel
            </button>
          </div>
        </form>
      )}

      {mode === "success" && (
        <div className="profile-password-success" role="status">
          <CircleCheck size={18} strokeWidth={1.9} />

          <span>Password changed successfully!</span>
        </div>
      )}
    </section>
  );
}
