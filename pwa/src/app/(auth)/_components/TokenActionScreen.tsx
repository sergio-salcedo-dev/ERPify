"use client";

import { useEffect, useId, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  AcceptInvitationSchema,
  type AcceptInvitationFormValues,
} from "@/context/backoffice/user/application/schemas/auth/AcceptInvitationSchema";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { AcceptInvitationRepository } from "@/context/backoffice/user/domain/AcceptInvitationRepository";
import {
  AcceptInvitationOutcomeKind,
  type AcceptInvitationOutcome,
} from "@/context/backoffice/user/domain/AcceptInvitationOutcome";
import { FormField } from "@/components/erpify";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui";
import { SecuritySignal } from "@/context/shared/access/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { useOnlineStatus } from "@/context/shared/connectivity/infrastructure/useOnlineStatus";
import { ConnectivityButton } from "@/context/shared/connectivity/infrastructure/ui/ConnectivityButton";
import { OfflineNotice } from "@/context/shared/connectivity/infrastructure/ui/OfflineNotice";

const ACCEPT_REPOSITORY_KEY = "BackOfficeAcceptInvitationRepository";
const PASSWORD_FIELD = "password";

/**
 * Sets the credential that activates an invited account. The opaque token is
 * read once from `?token=` and passed straight to the use case — never parsed,
 * persisted, or rendered; the query string is then stripped from the address
 * bar so the token survives only in memory. A missing or dead token collapses
 * to the neutral invalid-link wall (indistinguishable from every other
 * dead-token reason); a successful accept hands off to the
 * {@link SecuritySignal} success surface.
 */
export function TokenActionScreen() {
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
  const [accepted, setAccepted] = useState(false);
  const [invalidLink, setInvalidLink] = useState(false);
  const [requestFailed, setRequestFailed] = useState(false);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<AcceptInvitationFormValues>(AcceptInvitationSchema, {
    defaultValues: { password: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    // Render already guards `!token`; this keeps the closure total for TS.
    if (!token) return;
    setRequestFailed(false);
    const repo = container.get<AcceptInvitationRepository>(ACCEPT_REPOSITORY_KEY);
    let outcome: AcceptInvitationOutcome;
    try {
      outcome = await repo.accept({ token, password: values.password });
    } catch {
      // A network/transport failure is not an accept outcome; surface a neutral,
      // retryable error instead of leaving the form silently unresponsive.
      setRequestFailed(true);
      return;
    }

    if (outcome.kind === AcceptInvitationOutcomeKind.ACCEPTED) {
      // The 204 has set the httpOnly session cookie; re-probe `/me` so the
      // AuthProvider is authenticated before the success CTA enters the ERP
      // (otherwise RequireAuth would bounce the stale unauthenticated state).
      await login();
      setAccepted(true);
      return;
    }
    if (outcome.kind === AcceptInvitationOutcomeKind.INVALID_LINK) {
      setInvalidLink(true);
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

  if (!token || invalidLink) {
    return <AccessWall variant={AccessWallVariant.INVALID_LINK} />;
  }

  if (accepted) {
    return <SecuritySignal testId="accept-invitation__success" />;
  }

  return (
    <form
      onSubmit={onSubmit}
      className="space-y-4"
      noValidate
      aria-describedby={purposeId}
      data-testid="accept-invitation-form"
    >
      <h1 className="text-foreground text-xl font-semibold">Create your password</h1>
      <p id={purposeId} className="text-muted-foreground text-sm">
        Choose a password to activate your account and get started.
      </p>
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="accept-invitation-form__error"
        >
          Something went wrong. Please try again.
        </p>
      ) : null}
      <FormField name={PASSWORD_FIELD} label="Password" required error={errors.password?.message}>
        <PasswordInput
          autoComplete="new-password"
          defaultRevealed
          toggleTestId="accept-invitation-form__password-toggle"
          {...register(PASSWORD_FIELD)}
          data-testid="accept-invitation-form__password"
        />
      </FormField>
      {online ? null : <OfflineNotice testId="accept-invitation-form__offline" />}
      <ConnectivityButton
        loading={isSubmitting}
        disabled={!online}
        testId="accept-invitation-form__submit"
      >
        Activate account
      </ConnectivityButton>
    </form>
  );
}
