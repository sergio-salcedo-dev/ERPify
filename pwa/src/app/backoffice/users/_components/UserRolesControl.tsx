"use client";

import { useState } from "react";
import { MutationError } from "@/components/erpify";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { ChangeUserRoles } from "@/context/backoffice/user/application/ChangeUserRoles";
import type { User } from "@/context/backoffice/user/domain/User";
import { ALL_ROLES } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ChangeUserRolesSchema,
  type ChangeUserRolesFormValues,
} from "@/context/backoffice/user/application/schemas/ChangeUserRolesSchema";
import { ROLE_LABEL } from "../_lib/userLabels";

/** `"roles[0]"` / `"roles"` → the base field the violation belongs to. */
function baseField(field: string): string {
  return field.split(/[.[]/)[0];
}

interface UserRolesControlProps {
  user: User;
  /** Hands the re-granted identity back so the detail reflects the new role set in place. */
  onChanged: (user: User) => void;
}

/**
 * Role re-grant for an identity, gated by `users.changeRoles`. Offered in every lifecycle status: authorization
 * is orthogonal to the identity lifecycle, so a suspended or invited member's roles stay editable. The checkboxes
 * carry the COMPLETE target set (never a delta), pre-checked from the identity's current roles, so re-saving the
 * same set is a legitimate no-op. A 422 maps onto the `roles` field; every other failure (403 / 409 last-admin)
 * surfaces in the persistent {@link MutationError}.
 */
export function UserRolesControl({ user, onChanged }: Readonly<UserRolesControlProps>) {
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<ChangeUserRolesFormValues>(ChangeUserRolesSchema, {
    defaultValues: { roles: [...user.roles] },
  });

  const handleHttpError = (err: HttpError) => {
    const rolesViolation = err.problem.violations?.find(
      (violation) => baseField(violation.field) === "roles",
    );
    if (err.problem.status !== HttpStatus.UNPROCESSABLE_ENTITY || !rolesViolation) {
      setProblem(err.problem);
      return;
    }
    // Map the server-side violation onto the same RHF errors object client validation
    // populates, so both surface via `errors.roles?.message` with no parallel channel.
    setError("roles", { type: "server", message: rolesViolation.message });
    setProblem(null);
  };

  const onSubmit = handleSubmit(async (values) => {
    try {
      const useCase = container.get<ChangeUserRoles>("BackOfficeChangeUserRoles");
      const updated = await useCase.run(user.id, values.roles);
      setProblem(null);
      // Re-seed the checkboxes from what the server actually stored, so the form is the identity's
      // current set even if the backend normalised it.
      reset({ roles: [...updated.roles] });
      toastNotifier.success("Roles updated", { description: updated.roles.join(", ") });
      onChanged(updated);
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      handleHttpError(err);
    }
  });

  return (
    <Can permission={Permission.USERS_CHANGE_ROLES}>
      <form
        onSubmit={onSubmit}
        className="user-roles border-border space-y-3 rounded-md border p-4"
        data-testid="user-roles"
        noValidate
      >
        <div className="space-y-1">
          <h2 className="text-foreground text-base font-semibold">Roles</h2>
          <p className="text-muted-foreground text-sm">
            Grant what this member may do. Saving replaces their whole role set and signs them out
            of every device, so they sign back in with the new roles.
          </p>
        </div>

        {problem ? (
          <MutationError
            problem={problem}
            onDismiss={() => setProblem(null)}
            testId="user-roles__error"
          />
        ) : null}

        <fieldset
          className="user-roles__roles space-y-2"
          aria-describedby={errors.roles ? "user-roles__field-error" : undefined}
        >
          <legend className="text-foreground text-sm font-medium">
            Roles <span className="text-destructive">*</span>
          </legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {ALL_ROLES.map((role) => (
              <label
                key={role}
                className="border-border hover:bg-accent flex items-center gap-2 rounded-md border p-2 text-sm"
              >
                <input
                  type="checkbox"
                  value={role}
                  {...register("roles")}
                  disabled={isSubmitting}
                  className="border-input size-4 rounded"
                  data-testid={`user-roles__role-${role}`}
                />
                {ROLE_LABEL[role]}
              </label>
            ))}
          </div>
          {errors.roles ? (
            <p
              id="user-roles__field-error"
              role="alert"
              className="text-destructive text-sm"
              data-testid="user-roles__field-error"
            >
              {errors.roles.message}
            </p>
          ) : null}
        </fieldset>

        <button
          type="submit"
          className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex h-9 items-center justify-center rounded-md px-4 text-sm font-medium disabled:pointer-events-none disabled:opacity-50"
          disabled={isSubmitting}
          title={`Change ${user.email} roles`}
          aria-label="Change roles"
          data-testid="user-roles__save"
        >
          {isSubmitting ? "Saving…" : "Change roles"}
        </button>
      </form>
    </Can>
  );
}
