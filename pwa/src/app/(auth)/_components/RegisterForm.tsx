"use client";

import { useRouter } from "next/navigation";
import Link from "next/link";
import { useZodForm } from "@/context/shared/infrastructure/Validation";
import {
  RegisterSchema,
  type RegisterFormValues,
} from "@/context/backoffice/user/application/schemas/auth/RegisterSchema";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Routes } from "@/context/shared/domain/types/routes";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { safeHref } from "@/lib/safeHref";

export function RegisterForm() {
  const router = useRouter();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<RegisterFormValues>(RegisterSchema, {
    defaultValues: { email: "", password: "", confirmPassword: "" },
  });

  const onSubmit = handleSubmit(() => {
    // Mocked: the password is never persisted; we just route to sign-in.
    toastNotifier.success("Account created — please sign in");
    router.push(safeHref(Routes.LOGIN));
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="register-form">
      <h1 className="text-foreground text-xl font-semibold">Create account</h1>
      <FormField name="email" label="Email" required error={errors.email?.message}>
        <Input
          type="email"
          autoComplete="email"
          {...register("email")}
          data-testid="register-form__email"
        />
      </FormField>
      <FormField name="password" label="Password" required error={errors.password?.message}>
        <Input
          type="password"
          autoComplete="new-password"
          {...register("password")}
          data-testid="register-form__password"
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
          data-testid="register-form__confirm-password"
        />
      </FormField>
      <Button
        type="submit"
        disabled={isSubmitting}
        className="w-full"
        data-testid="register-form__submit"
      >
        Create account
      </Button>
      <div className="text-sm">
        <Link href={Routes.LOGIN} className="text-brand">
          Back to sign in
        </Link>
      </div>
    </form>
  );
}
