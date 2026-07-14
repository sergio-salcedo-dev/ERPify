"use client";

import { useCallback, useState } from "react";
import Link from "next/link";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ForgotPasswordSchema,
  type ForgotPasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ForgotPasswordSchema";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { ForgotPasswordRepository } from "@/context/backoffice/user/domain/ForgotPasswordRepository";
import { FormField } from "@/components/erpify";
import { Input } from "@/components/ui/input";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { useOnlineStatus } from "@/context/shared/connectivity/infrastructure/useOnlineStatus";
import { ConnectivityButton } from "@/context/shared/connectivity/infrastructure/ui/ConnectivityButton";
import { OfflineNotice } from "@/context/shared/connectivity/infrastructure/ui/OfflineNotice";

const FORGOT_REPOSITORY_KEY = "BackOfficeForgotPasswordRepository";
const EMAIL_FIELD = "email";
const BACK_TO_SIGN_IN = "Volver a iniciar sesión";

/**
 * Requests a password-reset link. The endpoint answers a uniform 202 for every
 * case, so a resolved call carries no signal about account existence: this screen
 * always shows the same confirmation and never reveals whether the address
 * matches an account (no enumeration). Only a transport fault surfaces a neutral,
 * retryable error — it is orthogonal to account existence, so it leaks nothing.
 */
export function ForgotPasswordForm() {
  const online = useOnlineStatus();
  const [requested, setRequested] = useState(false);
  const [requestFailed, setRequestFailed] = useState(false);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<ForgotPasswordFormValues>(ForgotPasswordSchema, {
    defaultValues: { email: "" },
  });

  // Callback ref: fires when the confirmation heading mounts (on the SPA
  // transition into the confirmation), moving focus to the single <h1>.
  const focusHeading = useCallback((node: HTMLHeadingElement | null) => {
    node?.focus();
  }, []);

  const onSubmit = handleSubmit(async (values) => {
    setRequestFailed(false);
    const repo = container.get<ForgotPasswordRepository>(FORGOT_REPOSITORY_KEY);
    try {
      await repo.request({ email: values.email });
    } catch {
      setRequestFailed(true);
      return;
    }
    setRequested(true);
  });

  if (requested) {
    return (
      <div className="space-y-4" data-testid="forgot-password__confirmation">
        <h1
          ref={focusHeading}
          tabIndex={-1}
          className="text-foreground text-xl font-semibold tracking-tight outline-none"
        >
          Revisa tu correo
        </h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Si esa dirección corresponde a una cuenta, te hemos enviado un enlace para restablecer tu
          contraseña.
        </p>
        <div className="text-sm">
          <Link
            href={safeHref(Routes.LOGIN)}
            className="text-brand"
            data-testid="forgot-password__confirmation-sign-in"
          >
            {BACK_TO_SIGN_IN}
          </Link>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="forgot-password-form">
      <h1 className="text-foreground text-xl font-semibold">Restablece tu contraseña</h1>
      <p className="text-muted-foreground text-sm">
        Introduce tu correo y te enviaremos un enlace para restablecerla.
      </p>
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="forgot-password-form__error"
        >
          No hemos podido completar la operación. Inténtalo de nuevo.
        </p>
      ) : null}
      <FormField
        name={EMAIL_FIELD}
        label="Correo electrónico"
        required
        error={errors.email?.message}
      >
        <Input
          type="email"
          autoComplete="email"
          {...register(EMAIL_FIELD)}
          data-testid="forgot-password-form__email"
        />
      </FormField>
      {online ? null : <OfflineNotice testId="forgot-password-form__offline" />}
      <ConnectivityButton
        loading={isSubmitting}
        disabled={!online}
        testId="forgot-password-form__submit"
      >
        Enviar enlace
      </ConnectivityButton>
      <div className="text-sm">
        <Link href={safeHref(Routes.LOGIN)} className="text-brand">
          {BACK_TO_SIGN_IN}
        </Link>
      </div>
    </form>
  );
}
