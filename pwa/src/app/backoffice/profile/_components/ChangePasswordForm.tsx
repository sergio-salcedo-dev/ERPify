"use client";

import { useState, useSyncExternalStore } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { IdentityRepository } from "@/context/shared/access/domain/IdentityRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type {
  ProblemDetails,
  ProblemViolation,
} from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ChangePasswordSchema,
  type ChangePasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ChangePasswordSchema";
import { FormField, MutationError, Spinner } from "@/components/erpify";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { Button } from "@/components/ui/button";

const IDENTITY_REPOSITORY_KEY = "IdentityRepository";

// The sign-out of the other sessions is requested best-effort and the 204 carries no word on whether it
// landed, so the copy states the request and points at the surface that shows the truth — the same reason
// the notification email declines to claim the sessions were closed.
const PASSWORD_CHANGED_TITLE =
  "Password updated. We've also asked your other sessions to sign out — check Active sessions if a device still looks open.";

/** Problem types the API hangs on a single field instead of a `violations[]` entry. */
const INVALID_CURRENT_PASSWORD = "invalid-current-password";
const NEW_PASSWORD_MUST_DIFFER = "new-password-must-differ";

const CHANGE_PASSWORD_FIELDS = ["currentPassword", "newPassword"] as const;
type ChangePasswordFieldName = (typeof CHANGE_PASSWORD_FIELDS)[number];

function isChangePasswordField(value: string): value is ChangePasswordFieldName {
  return (CHANGE_PASSWORD_FIELDS as readonly string[]).includes(value);
}

/** `"newPassword"` / `"newPassword[0]"` → the base field the violation belongs to. */
function baseField(field: string): string {
  return field.split(/[.[]/)[0];
}

// Stable no-op subscribe: the "has the client hydrated" flag never changes after the
// hydration commit, so `useSyncExternalStore` needs only its server (`false`) vs client
// (`true`) snapshots to expose it.
const emptySubscribe = () => () => {};

/**
 * Changes the signed-in user's own password. It talks to the `IdentityRepository`
 * port — the same aggregate `/me` reads — and never inspects status codes itself:
 * the two policy rejections arrive as problem `type`s and are hung on the field they
 * belong to, so the user reads them where they typed. Anything else stays in the
 * persistent `<MutationError>` banner with its correlation id.
 */
export function ChangePasswordForm() {
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [changed, setChanged] = useState(false);
  // A failure that is not an `HttpError` is not this form's to explain — a broken container binding is not a
  // rejected password. Throwing it from the submit callback only rejects a promise nobody awaits, so the
  // button appears to do nothing; parking it here and re-throwing during render hands it to the segment
  // error boundary, which is the surface this application already built for "something broke".
  const [fatal, setFatal] = useState<unknown>(null);

  // Until React wires the submit handler, a native submit performs a GET that would put
  // both passwords into the URL, history and access logs. Gating the submit button on
  // hydration stops any submit — click or Enter — from firing that native GET.
  const hydrated = useSyncExternalStore(
    emptySubscribe,
    () => true,
    () => false,
  );

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useZodForm<ChangePasswordFormValues>(ChangePasswordSchema, {
    defaultValues: { currentPassword: "", newPassword: "" },
  });

  const failField = (field: ChangePasswordFieldName, problemDetails: ProblemDetails): void => {
    setError(
      field,
      { type: "server", message: problemDetails.detail ?? problemDetails.title },
      { shouldFocus: true },
    );
    setProblem(null);
  };

  // The entries arrive already narrowed by the caller's guard rather than being re-widened here: a
  // `?? []` at the loop would be a branch no input can reach, and the only way to cover it would be to
  // fabricate a call the code does not make.
  const failViolations = (problemDetails: ProblemDetails, entries: ProblemViolation[]): void => {
    // Map server-side violations onto the same RHF errors object client validation
    // populates, so both surface via `errors[name]?.message` with no parallel channel.
    let mappedAny = false;
    let unmappedExist = false;
    for (const violation of entries) {
      const field = baseField(violation.field);
      if (isChangePasswordField(field)) {
        setError(field, { type: "server", message: violation.message });
        mappedAny = true;
      } else {
        unmappedExist = true;
      }
    }
    setProblem(mappedAny && !unmappedExist ? null : problemDetails);
  };

  const handleHttpError = (error: HttpError): void => {
    const { problem: problemDetails } = error;
    if (problemDetails.type === INVALID_CURRENT_PASSWORD) {
      failField("currentPassword", problemDetails);
      return;
    }
    if (problemDetails.type === NEW_PASSWORD_MUST_DIFFER) {
      failField("newPassword", problemDetails);
      return;
    }
    if (
      problemDetails.status === HttpStatus.UNPROCESSABLE_ENTITY &&
      problemDetails.violations?.length
    ) {
      failViolations(problemDetails, problemDetails.violations);
      return;
    }
    setProblem(problemDetails);
  };

  const onSubmit = handleSubmit(async (values) => {
    try {
      const identities = container.get<IdentityRepository>(IDENTITY_REPOSITORY_KEY);
      await identities.changePassword(values);
      setProblem(null);
      setChanged(true);
    } catch (error) {
      if (!(error instanceof HttpError)) {
        setFatal(() => error);
        return;
      }
      handleHttpError(error);
    }
  });

  if (fatal !== null) throw fatal;

  // Deliberately not `<SecuritySignal>`: that card owns an `<h1>` it moves focus to and a
  // "Enter the dashboard" link, both of which belong to the token-action screens it was
  // built for. Here the surface already has its heading, and the user is already inside.
  if (changed) {
    return (
      <div
        className="change-password-form__success border-border bg-card space-y-3 rounded-lg border p-4"
        data-testid="change-password-form__success"
      >
        <output className="change-password-form__success-title text-foreground flex items-center gap-2 text-sm font-medium">
          <span
            className="change-password-form__success-dot bg-success size-2 shrink-0 rounded-full"
            aria-hidden="true"
          />
          {PASSWORD_CHANGED_TITLE}
        </output>
        <Button
          type="button"
          variant="outline"
          size="sm"
          title="Change your password again"
          aria-label="Change password again"
          data-testid="change-password-form__change-again"
          onClick={() => {
            reset();
            setChanged(false);
          }}
        >
          Change password again
        </Button>
      </div>
    );
  }

  return (
    <form
      onSubmit={onSubmit}
      className="change-password-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="change-password-form"
      data-hydrated={hydrated ? "true" : undefined}
      noValidate
    >
      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          testId="change-password-form__mutation-error"
        />
      ) : null}

      <FormField
        name="currentPassword"
        label="Current password"
        required
        error={errors.currentPassword?.message}
      >
        <PasswordInput
          autoComplete="current-password"
          defaultRevealed={false}
          toggleTestId="change-password-form__current-password-toggle"
          {...register("currentPassword")}
          data-testid="change-password-form__current-password"
        />
      </FormField>

      <FormField
        name="newPassword"
        label="New password"
        required
        error={errors.newPassword?.message}
        helper="Between 8 and 128 characters, and different from your current password."
      >
        <PasswordInput
          autoComplete="new-password"
          defaultRevealed={false}
          toggleTestId="change-password-form__new-password-toggle"
          {...register("newPassword")}
          data-testid="change-password-form__new-password"
        />
      </FormField>

      <footer className="change-password-form__footer flex justify-end pt-2">
        <Button
          type="submit"
          size="sm"
          disabled={isSubmitting || !hydrated}
          data-icon={isSubmitting ? "inline-start" : undefined}
          aria-label="Update password"
          title="Update password"
          className="w-full sm:w-auto"
          data-testid="change-password-form__submit"
        >
          {isSubmitting ? (
            <>
              <Spinner className="size-3.5" testId="change-password-form__submit-spinner" />
              Updating…
            </>
          ) : (
            "Update password"
          )}
        </Button>
      </footer>
    </form>
  );
}
