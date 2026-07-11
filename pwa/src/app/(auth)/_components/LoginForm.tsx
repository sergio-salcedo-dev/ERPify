"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  LoginSchema,
  type LoginFormValues,
} from "@/context/backoffice/user/application/schemas/auth/LoginSchema";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { LoginRepository } from "@/context/backoffice/user/domain/LoginRepository";
import { LoginOutcomeKind, type LoginOutcome } from "@/context/backoffice/user/domain/LoginOutcome";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";

export function LoginForm() {
  const router = useRouter();
  const { login } = useSession();
  const [wall, setWall] = useState<AccessWallVariant | null>(null);
  const [credentialsError, setCredentialsError] = useState(false);
  const [requestFailed, setRequestFailed] = useState(false);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<LoginFormValues>(LoginSchema, { defaultValues: { email: "", password: "" } });

  const onSubmit = handleSubmit(async (values) => {
    setCredentialsError(false);
    setRequestFailed(false);
    const repo = container.get<LoginRepository>("BackOfficeLoginRepository");
    let outcome: LoginOutcome;
    try {
      outcome = await repo.login({ email: values.email, password: values.password });
    } catch {
      // A network/transport failure is not a login outcome; surface a neutral,
      // retryable error instead of leaving the form silently unresponsive.
      setRequestFailed(true);
      return;
    }

    if (outcome.kind === LoginOutcomeKind.AUTHENTICATED) {
      // The 204 has set the httpOnly session cookie; hydrate the real identity
      // from `/me` (no fabricated ADMIN) before leaving the login page.
      await login();
      toastNotifier.success("Signed in");
      // Return to the deep link RequireAuth stashed in `?next=`, falling back to
      // the back-office root. safeInternalPath rejects any off-origin target.
      const next = new URLSearchParams(globalThis.location.search).get("next");
      router.push(safeHref(safeInternalPath(next, Routes.BACKOFFICE)));
      return;
    }
    if (outcome.kind === LoginOutcomeKind.SUSPENDED) {
      setWall(AccessWallVariant.SUSPENDED);
      return;
    }
    if (outcome.kind === LoginOutcomeKind.DEACTIVATED) {
      setWall(AccessWallVariant.DEACTIVATED);
      return;
    }
    setCredentialsError(true);
  });

  if (wall) {
    return <AccessWall variant={wall} />;
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="login-form">
      <h1 className="text-foreground text-xl font-semibold">Sign in</h1>
      {credentialsError ? (
        <p role="alert" className="text-danger-strong text-sm" data-testid="login-form__error">
          Invalid email or password.
        </p>
      ) : null}
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="login-form__request-error"
        >
          Something went wrong. Please try again.
        </p>
      ) : null}
      <FormField name="email" label="Email" required error={errors.email?.message}>
        <Input
          type="email"
          autoComplete="email"
          {...register("email")}
          data-testid="login-form__email"
        />
      </FormField>
      <FormField name="password" label="Password" required error={errors.password?.message}>
        <Input
          type="password"
          autoComplete="current-password"
          {...register("password")}
          data-testid="login-form__password"
        />
      </FormField>
      <Button
        type="submit"
        disabled={isSubmitting}
        className="w-full"
        data-testid="login-form__submit"
      >
        Sign in
      </Button>
      <div className="flex justify-between text-sm">
        <Link href={Routes.FORGOT_PASSWORD} className="text-brand">
          Forgot password?
        </Link>
        <Link href={Routes.REGISTER} className="text-brand">
          Create account
        </Link>
      </div>
    </form>
  );
}
