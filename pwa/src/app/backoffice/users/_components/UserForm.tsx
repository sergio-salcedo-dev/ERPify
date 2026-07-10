"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  UserFormSchema,
  type UserFormValues,
} from "@/context/backoffice/user/application/schemas/UserFormSchema";
import type { UserInput, UserRepository } from "@/context/backoffice/user/domain/UserRepository";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";
import { ALL_ROLES, type Role } from "@/context/shared/access/domain/Role";
import { ALL_PERMISSIONS } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { FormField, MutationError, Spinner } from "@/components/erpify";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { userRoutes } from "../_lib/userRoutes";
import { ROLE_LABEL, STATUS_LABEL } from "../_lib/userLabels";

export type UserFormMode = typeof PersistenceAction.CREATING | typeof PersistenceAction.UPDATING;

interface UserFormInitial {
  id: string;
  email: string;
  roles: Role[];
  status: UserStatus;
  permissions: UserFormValues["permissions"];
}

interface UserFormProps {
  mode: UserFormMode;
  initial?: UserFormInitial;
  /** Edit-only recovery hook for a stale 404 (`user-not-found`). */
  onStaleUser?: () => void;
}

const USER_FIELD_NAMES = ["email", "roles", "status", "permissions"] as const;
type UserFieldName = (typeof USER_FIELD_NAMES)[number];
function isUserFieldName(value: string): value is UserFieldName {
  return (USER_FIELD_NAMES as readonly string[]).includes(value);
}

export function UserForm({ mode, initial, onStaleUser }: Readonly<UserFormProps>) {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  // Hydration marker: a click before React wires submit would otherwise leak
  // the values via a native GET. QA waits on this attribute.
  const formRef = useRef<HTMLFormElement>(null);
  useEffect(() => {
    formRef.current?.setAttribute("data-hydrated", "true");
  }, []);

  const isCreating = mode === PersistenceAction.CREATING;

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<UserFormValues>(UserFormSchema, {
    defaultValues: {
      email: initial?.email ?? "",
      roles: initial?.roles ?? [],
      status: initial?.status ?? UserStatus.INVITED,
      permissions: initial?.permissions ?? [],
    },
  });

  const submitting = isSubmitting;

  const handleHttpError = (err: HttpError): void => {
    if (err.problem.status !== HttpStatus.BAD_REQUEST || !err.problem.violations) {
      setProblem(err.problem);
      return;
    }
    let mappedAny = false;
    let unmappedExist = false;
    for (const violation of err.problem.violations) {
      if (isUserFieldName(violation.field)) {
        setError(violation.field, { type: "server", message: violation.message });
        mappedAny = true;
      } else {
        unmappedExist = true;
      }
    }
    if (!mappedAny || unmappedExist) {
      setProblem(err.problem);
      return;
    }
    setProblem(null);
  };

  const onSubmit = handleSubmit(async (values) => {
    const input: UserInput = {
      email: values.email,
      roles: values.roles,
      status: values.status,
      permissions: values.permissions,
    };
    try {
      const repo = container.get<UserRepository>("BackOfficeUserRepository");
      if (isCreating) {
        const created = await repo.create(input);
        setProblem(null);
        toastNotifier.success("User created", { description: created.email });
        router.push(safeHref(userRoutes.detail(created.id)));
        router.refresh();
        return;
      }
      if (!initial) {
        throw new Error("UserForm in edit mode requires `initial`.");
      }
      const updated = await repo.update(initial.id, input);
      setProblem(null);
      toastNotifier.success("Changes saved", { description: updated.email });
      router.push(safeHref(userRoutes.detail(updated.id)));
      router.refresh();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      handleHttpError(err);
    }
  });

  const cancelHref = safeHref(
    mode === PersistenceAction.UPDATING && initial
      ? userRoutes.detail(initial.id)
      : userRoutes.list,
  );

  const recoveryAction =
    !isCreating && problem?.type === UserProblemType.NOT_FOUND && onStaleUser ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onStaleUser}
        aria-label="Refresh"
        title="Refresh this user"
        data-testid="user-form__mutation-error-refresh"
      >
        Refresh
      </Button>
    ) : undefined;

  const idleSubmitLabel = isCreating ? "Create user" : "Save changes";
  const cancelLabel = isCreating ? "Cancel and go back" : "Cancel and go back to user";
  const submitActionLabel = isCreating ? "Create user" : "Save user changes";

  return (
    <form
      ref={formRef}
      onSubmit={onSubmit}
      className="user-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="user-form"
      noValidate
    >
      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          action={recoveryAction}
          testId="user-form__mutation-error"
        />
      ) : null}

      <FormField name="email" label="Email" required error={errors.email?.message}>
        <Input
          type="email"
          autoComplete="off"
          readOnly={!isCreating}
          autoFocus={isCreating}
          {...register("email")}
          data-testid="user-form__email"
        />
      </FormField>

      <FormField name="roles" label="Roles" required error={errors.roles?.message}>
        <fieldset className="user-form__roles flex flex-wrap gap-x-4 gap-y-2">
          <legend className="sr-only">Roles</legend>
          {ALL_ROLES.map((role) => (
            <label key={role} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                value={role}
                {...register("roles")}
                className="accent-primary border-border size-4 rounded"
                data-testid={`user-form__role-${role}`}
              />
              {ROLE_LABEL[role]}
            </label>
          ))}
        </fieldset>
      </FormField>

      <FormField name="status" label="Status" required error={errors.status?.message}>
        <select
          className="border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          {...register("status")}
          data-testid="user-form__status"
        >
          {Object.values(UserStatus).map((status) => (
            <option key={status} value={status}>
              {STATUS_LABEL[status]}
            </option>
          ))}
        </select>
      </FormField>

      {isCreating ? null : (
        <FormField
          name="permissions"
          label="Permissions"
          error={errors.permissions?.message}
          helper="Fine-grained permissions granted on top of the user's roles."
        >
          <fieldset className="user-form__permissions grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
            <legend className="sr-only">Permissions</legend>
            {ALL_PERMISSIONS.map((permission) => (
              <label key={permission} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  value={permission}
                  {...register("permissions")}
                  className="accent-primary border-border size-4 rounded"
                  data-testid={`user-form__permission-${permission}`}
                />
                <span className="font-mono text-xs">{permission}</span>
              </label>
            ))}
          </fieldset>
        </FormField>
      )}

      <footer className="user-form__footer flex flex-col-reverse items-stretch gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Link
          href={cancelHref}
          aria-disabled={submitting || undefined}
          aria-label={cancelLabel}
          title={cancelLabel}
          tabIndex={submitting ? -1 : undefined}
          onClick={(event) => {
            if (submitting) event.preventDefault();
          }}
          className={cn(
            buttonVariants({ variant: "ghost", size: "sm" }),
            "w-full sm:w-auto",
            submitting && "pointer-events-none opacity-50",
          )}
        >
          Cancel
        </Link>
        <Button
          type="submit"
          size="sm"
          disabled={submitting}
          data-icon={submitting ? "inline-start" : undefined}
          aria-label={submitActionLabel}
          title={submitActionLabel}
          className="w-full sm:w-auto"
          data-testid="user-form__submit"
        >
          {submitting ? (
            <>
              <Spinner className="size-3.5" testId="user-form__submit-spinner" />
              Saving…
            </>
          ) : (
            idleSubmitLabel
          )}
        </Button>
      </footer>
    </form>
  );
}
