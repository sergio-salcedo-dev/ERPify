"use client";

import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { useZodForm } from "@/context/shared/infrastructure/Validation";
import {
  ResetPasswordSchema,
  type ResetPasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ResetPasswordSchema";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Routes } from "@/context/shared/domain/types/routes";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { safeHref } from "@/lib/safeHref";

export function ResetPasswordForm() {
  const router = useRouter();
  // The reset token arrives in the link emailed to the user. Server-side
  // validation (opaque-token check, expiry) lands with the real auth API; until
  // then a missing token is the one case we can already reject.
  const token = useSearchParams().get("token");
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<ResetPasswordFormValues>(ResetPasswordSchema, {
    defaultValues: { password: "", confirmPassword: "" },
  });

  const onSubmit = handleSubmit(() => {
    // Mocked: the new password is never persisted; route to sign-in.
    toastNotifier.success("Password updated — please sign in");
    router.push(safeHref(Routes.LOGIN));
  });

  if (!token) {
    return (
      <div className="space-y-4" data-testid="reset-password-form__invalid">
        <h1 className="text-foreground text-xl font-semibold">Invalid or expired link</h1>
        <p className="text-muted-foreground text-sm">
          This password reset link is invalid or has expired. Request a new one to continue.
        </p>
        <div className="text-sm">
          <Link href={Routes.FORGOT_PASSWORD} className="text-brand">
            Request a new link
          </Link>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="reset-password-form">
      <h1 className="text-foreground text-xl font-semibold">Choose a new password</h1>
      <FormField name="password" label="New password" required error={errors.password?.message}>
        <Input
          type="password"
          autoComplete="new-password"
          {...register("password")}
          data-testid="reset-password-form__password"
        />
      </FormField>
      <FormField
        name="confirmPassword"
        label="Confirm password"
        required
        error={errors.confirmPassword?.message}
      >
        <Input
          type="password"
          autoComplete="new-password"
          {...register("confirmPassword")}
          data-testid="reset-password-form__confirm-password"
        />
      </FormField>
      <Button
        type="submit"
        disabled={isSubmitting}
        className="w-full"
        data-testid="reset-password-form__submit"
      >
        Update password
      </Button>
      <div className="text-sm">
        <Link href={Routes.LOGIN} className="text-brand">
          Back to sign in
        </Link>
      </div>
    </form>
  );
}
