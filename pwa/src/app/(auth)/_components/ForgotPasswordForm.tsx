"use client";

import Link from "next/link";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ForgotPasswordSchema,
  type ForgotPasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ForgotPasswordSchema";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";

export function ForgotPasswordForm() {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<ForgotPasswordFormValues>(ForgotPasswordSchema, { defaultValues: { email: "" } });

  const onSubmit = handleSubmit(() => {
    // Mocked: always reports the neutral, non-enumerating message.
    toastNotifier.success("If that email exists, a reset link was sent");
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="forgot-password-form">
      <h1 className="text-foreground text-xl font-semibold">Reset your password</h1>
      <p className="text-muted-foreground text-sm">
        Enter your email and we&apos;ll send a reset link.
      </p>
      <FormField name="email" label="Email" required error={errors.email?.message}>
        <Input
          type="email"
          autoComplete="email"
          {...register("email")}
          data-testid="forgot-password-form__email"
        />
      </FormField>
      <Button
        type="submit"
        disabled={isSubmitting}
        className="w-full"
        data-testid="forgot-password-form__submit"
      >
        Send reset link
      </Button>
      <div className="text-sm">
        <Link href={Routes.LOGIN} className="text-brand">
          Back to sign in
        </Link>
      </div>
    </form>
  );
}
