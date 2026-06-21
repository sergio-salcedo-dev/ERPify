"use client";

import { useRouter } from "next/navigation";
import Link from "next/link";
import { useZodForm } from "@/context/shared/Validation/infrastructure";
import {
  LoginSchema,
  type LoginFormValues,
} from "@/context/backoffice/user/application/schemas/auth/LoginSchema";
import { FormField } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useSession } from "@/context/shared/access/application/useSession";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { PERMISSION_WILDCARD } from "@/context/shared/access/domain/Permission";
import { Routes } from "@/context/shared/domain/types/routes";
import { toastNotifier } from "@/context/shared/Notification/infrastructure/Toast";
import { uuidV7 } from "@/lib/uuidV7";
import { safeHref } from "@/lib/safeHref";
import { safeInternalPath } from "@/lib/safeInternalPath";

export function LoginForm() {
  const router = useRouter();
  const { login } = useSession();
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<LoginFormValues>(LoginSchema, { defaultValues: { email: "", password: "" } });

  const onSubmit = handleSubmit((values) => {
    // Mocked: no validation, seed an ADMIN identity from the typed email. The
    // password is never stored.
    login({
      id: uuidV7(),
      email: values.email,
      status: UserStatus.ACTIVE,
      roles: [Role.ADMIN],
      permissions: [PERMISSION_WILDCARD],
    });
    toastNotifier.success("Signed in");
    // Return to the deep link RequireAuth stashed in `?next=`, falling back to
    // the back-office root. safeInternalPath rejects any off-origin target.
    const next = new URLSearchParams(globalThis.location.search).get("next");
    router.push(safeHref(safeInternalPath(next, Routes.BACKOFFICE)));
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate data-testid="login-form">
      <h1 className="text-foreground text-xl font-semibold">Sign in</h1>
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
