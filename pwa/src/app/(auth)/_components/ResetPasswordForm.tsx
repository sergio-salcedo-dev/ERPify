"use client";

import { type FormEvent, useEffect, useId, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ResetPasswordSchema,
  type ResetPasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { ResetPasswordRepository } from "@/context/backoffice/user/domain/ResetPasswordRepository";
import {
  ResetPasswordOutcomeKind,
  type ResetPasswordOutcome,
} from "@/context/backoffice/user/domain/ResetPasswordOutcome";
import { FormField } from "@/components/erpify";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui";
import { SecuritySignal } from "@/context/shared/access/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { useOnlineStatus } from "@/context/shared/connectivity/infrastructure/useOnlineStatus";
import { ConnectivityButton } from "@/context/shared/connectivity/infrastructure/ui/ConnectivityButton";
import { OfflineNotice } from "@/context/shared/connectivity/infrastructure/ui/OfflineNotice";

const RESET_REPOSITORY_KEY = "BackOfficeResetPasswordRepository";
const PASSWORD_FIELD = "password";
const PASSWORD_CHANGED_TITLE = "Password updated. We've signed out your other open sessions.";

/**
 * Sets a new credential from the emailed reset link. The opaque token is read
 * once from `?token=` and passed straight to the use case — never parsed,
 * persisted, or rendered; the query string is then stripped from the address
 * bar so the token survives only in memory. A missing or dead token collapses
 * to the neutral invalid-link wall (indistinguishable from every other
 * dead-token reason); a suspended/deactivated account shows the matching wall;
 * a successful reset signs the user in and hands off to the
 * {@link SecuritySignal} success surface (which reports that other sessions
 * were closed).
 */
export function ResetPasswordForm() {
  const searchParams = useSearchParams();
  // Captured in state so the token outlives the URL strip below.
  const [token] = useState(() => searchParams.get("token"));
  // Drop the query string from the address bar and history entry so the token
  // cannot linger in browser history or leak through a Referer header.
  useEffect(() => {
    window.history.replaceState(null, "", window.location.pathname);
  }, []);
  const { login } = useSession();
  const online = useOnlineStatus();
  const purposeId = useId();
  const [reset, setReset] = useState(false);
  const [wall, setWall] = useState<AccessWallVariant | null>(null);
  const [requestFailed, setRequestFailed] = useState(false);
  // In-flight latch: ConnectivityButton disables only the button, so pressing
  // Enter in the password field while a submit runs would re-fire the form and
  // reset the same token twice — the loser's 400 invalid-token would then tap
  // the invalid-link wall over the success surface. Read/written only in the
  // form's own submit handler.
  const submitting = useRef(false);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<ResetPasswordFormValues>(ResetPasswordSchema, {
    defaultValues: { password: "" },
  });

  const submitReset = handleSubmit(async (values) => {
    // Render already guards `!token`; this keeps the closure total for TS.
    if (!token) return;
    setRequestFailed(false);
    const repo = container.get<ResetPasswordRepository>(RESET_REPOSITORY_KEY);
    let outcome: ResetPasswordOutcome;
    try {
      outcome = await repo.reset({ token, password: values.password });
    } catch {
      // A network/transport failure is not a reset outcome; surface a neutral,
      // retryable error instead of leaving the form silently unresponsive.
      setRequestFailed(true);
      return;
    }

    if (outcome.kind === ResetPasswordOutcomeKind.RESET) {
      // The 204 has set the httpOnly session cookie; re-probe `/me` so the
      // AuthProvider is authenticated before the success CTA enters the ERP
      // (otherwise RequireAuth would bounce the stale unauthenticated state).
      await login();
      setReset(true);
      return;
    }
    if (outcome.kind === ResetPasswordOutcomeKind.INVALID_LINK) {
      setWall(AccessWallVariant.INVALID_RESET_LINK);
      return;
    }
    if (outcome.kind === ResetPasswordOutcomeKind.SUSPENDED) {
      setWall(AccessWallVariant.SUSPENDED);
      return;
    }
    if (outcome.kind === ResetPasswordOutcomeKind.DEACTIVATED) {
      setWall(AccessWallVariant.DEACTIVATED);
      return;
    }
    // Validation error: map each password violation onto the same RHF error
    // channel client validation uses, moving focus to the field. A violation
    // that names no known field falls back to the generic retryable error.
    let mappedAny = false;
    for (const violation of outcome.violations) {
      if (violation.field === PASSWORD_FIELD) {
        setError(
          PASSWORD_FIELD,
          { type: "server", message: violation.message },
          { shouldFocus: true },
        );
        mappedAny = true;
      }
    }
    if (!mappedAny) setRequestFailed(true);
  });

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    if (submitting.current) {
      event.preventDefault();
      return;
    }
    submitting.current = true;
    // handleSubmit's promise settles after a validation reject too, so releasing
    // the latch here (rather than inside the async body) also frees a submit that
    // never reached the port because client validation failed.
    return submitReset(event).finally(() => {
      submitting.current = false;
    });
  };

  if (!token) {
    return <AccessWall variant={AccessWallVariant.INVALID_RESET_LINK} />;
  }

  if (wall) {
    return <AccessWall variant={wall} />;
  }

  if (reset) {
    return <SecuritySignal title={PASSWORD_CHANGED_TITLE} testId="reset-password__success" />;
  }

  return (
    <form
      onSubmit={onSubmit}
      className="space-y-4"
      noValidate
      aria-describedby={purposeId}
      data-testid="reset-password-form"
    >
      <h1 className="text-foreground text-xl font-semibold">Choose a new password</h1>
      <p id={purposeId} className="text-muted-foreground text-sm">
        Create a new password to regain access to your account.
      </p>
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="reset-password-form__error"
        >
          Something went wrong. Please try again.
        </p>
      ) : null}
      <FormField
        name={PASSWORD_FIELD}
        label="New password"
        required
        error={errors.password?.message}
      >
        <PasswordInput
          autoComplete="new-password"
          defaultRevealed
          toggleTestId="reset-password-form__password-toggle"
          {...register(PASSWORD_FIELD)}
          data-testid="reset-password-form__password"
        />
      </FormField>
      {online ? null : <OfflineNotice testId="reset-password-form__offline" />}
      <ConnectivityButton
        loading={isSubmitting}
        disabled={!online}
        testId="reset-password-form__submit"
      >
        Update password
      </ConnectivityButton>
    </form>
  );
}
