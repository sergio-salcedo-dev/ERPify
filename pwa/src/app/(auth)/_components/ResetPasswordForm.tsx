"use client";

import { useId, useState } from "react";
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
const PASSWORD_CHANGED_TITLE = "Contraseña actualizada. Hemos cerrado tus otras sesiones abiertas.";

/**
 * Sets a new credential from the emailed reset link. The opaque token is read
 * from `?token=` and passed straight to the use case — never parsed, stored, or
 * rendered. A missing or dead token collapses to the neutral invalid-link wall
 * (indistinguishable from every other dead-token reason); a suspended/deactivated
 * account shows the matching wall; a successful reset signs the user in and hands
 * off to the {@link SecuritySignal} success surface (which reports that other
 * sessions were closed).
 */
export function ResetPasswordForm() {
  const token = useSearchParams().get("token");
  const { login } = useSession();
  const online = useOnlineStatus();
  const purposeId = useId();
  const [reset, setReset] = useState(false);
  const [wall, setWall] = useState<AccessWallVariant | null>(null);
  const [requestFailed, setRequestFailed] = useState(false);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<ResetPasswordFormValues>(ResetPasswordSchema, {
    defaultValues: { password: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
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
      setWall(AccessWallVariant.INVALID_LINK);
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

  if (!token) {
    return <AccessWall variant={AccessWallVariant.INVALID_LINK} />;
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
      <h1 className="text-foreground text-xl font-semibold">Elige una nueva contraseña</h1>
      <p id={purposeId} className="text-muted-foreground text-sm">
        Crea una contraseña nueva para recuperar el acceso a tu cuenta.
      </p>
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="reset-password-form__error"
        >
          No hemos podido completar la operación. Inténtalo de nuevo.
        </p>
      ) : null}
      <FormField
        name={PASSWORD_FIELD}
        label="Nueva contraseña"
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
        Actualizar contraseña
      </ConnectivityButton>
    </form>
  );
}
