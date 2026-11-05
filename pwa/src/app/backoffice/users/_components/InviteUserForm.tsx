"use client";

import { useRef, useState, useSyncExternalStore, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { InviteUser } from "@/context/backoffice/user/application/InviteUser";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { SharedProblemType } from "@/context/shared/error/domain/SharedProblemType";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  InviteUserSchema,
  type InviteUserFormValues,
} from "@/context/backoffice/user/application/schemas/InviteUserSchema";
import { ALL_ROLES, Role } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";
import { useCan } from "@/context/shared/access/application/useCan";
import { FormField, MutationError, Spinner } from "@/components/erpify";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { ROLE_LABEL } from "../_lib/userLabels";
import { userRoutes } from "../_lib/userRoutes";

const INVITE_FIELD_NAMES = ["email", "roles", "currentPassword"] as const;
type InviteFieldName = (typeof INVITE_FIELD_NAMES)[number];

/**
 * A lost uniqueness race carries no `violations` — the API cannot ask which value collided once the
 * failed commit closed its unit of work — so the field-level wording is the client's to write. It says
 * "again" rather than "already in use" because the two outcomes differ: the address was free when this
 * request was checked, and a resubmit is what tells the user which of the two it really is.
 */
const EMAIL_TAKEN_IN_FLIGHT = "That email was just taken by another request. Try sending it again.";

function isInviteFieldName(value: string): value is InviteFieldName {
  return (INVITE_FIELD_NAMES as readonly string[]).includes(value);
}

/** `"roles[0]"` / `"email"` → the base field the violation belongs to. */
function baseField(field: string): string {
  return field.split(/[.[]/)[0];
}

// Stable no-op subscribe: the "has the client hydrated" flag never changes after the hydration commit,
// so `useSyncExternalStore` needs only its server (`false`) vs client (`true`) snapshots to expose it.
const emptySubscribe = () => () => {};

/**
 * Sends the invitation that creates an identity. The ADMIN checkbox is offered only to a session holding
 * `users.grantAdmin`: minting another administrator is a capability of its own, distinct from being allowed to
 * invite at all. The gate is `useCan` rather than a `<Can>` wrapper because the decision is a filter over the
 * role list — one predicate resolved once, before the `.map()` — where `<Can>` would put a guard component
 * inside every checkbox to answer a question that concerns a single role. Hiding is convenience only: an
 * `ADMIN` value submitted anyway is refused by the API, which remains the authorization control.
 *
 * Granting ADMIN also asks for the inviter's own current password, and only then: the field appears with the
 * checkbox, leaves with it (taking the typed value along), and is sent solely when ADMIN is among the roles.
 * Minting an administrator is the one authority a stolen session must not be able to plant on its own.
 */
export function InviteUserForm() {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const mayGrantAdmin = useCan(Permission.USERS_GRANT_ADMIN);
  const offeredRoles = mayGrantAdmin ? ALL_ROLES : ALL_ROLES.filter((role) => role !== Role.ADMIN);

  // Until React wires the submit handler, a native submit performs a GET that leaks the invitee email
  // into the URL, history and logs. Gating the submit button on hydration stops any submit — click or
  // Enter — from firing that native GET; the mirrored `data-hydrated` also lets automation wait.
  const hydrated = useSyncExternalStore(
    emptySubscribe,
    () => true,
    () => false,
  );

  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useZodForm<InviteUserFormValues>(InviteUserSchema, {
    defaultValues: { email: "", roles: [], currentPassword: "" },
  });

  const selectedRoles = watch("roles");
  const grantsAdmin = Array.isArray(selectedRoles) && selectedRoles.includes(Role.ADMIN);

  const handleHttpError = (err: HttpError) => {
    // A wrong password is a field error, never a denial: the 403 says the proof failed, not that the
    // inviter may not invite.
    if (err.problem.type === UserProblemType.INVALID_CURRENT_PASSWORD) {
      setError(
        "currentPassword",
        { type: "server", message: err.problem.detail ?? err.problem.title },
        { shouldFocus: true },
      );
      setProblem(null);
      return;
    }
    // The conflict is the one failure whose remedy is a plain resubmit, so it earns both surfaces:
    // the field, because the address is what collided, and the persistent surface, which is where
    // the retry action lives.
    if (
      err.problem.status === HttpStatus.CONFLICT &&
      err.problem.type === SharedProblemType.CONCURRENT_UNIQUE_WRITE
    ) {
      setError("email", { type: "server", message: EMAIL_TAKEN_IN_FLIGHT });
      setProblem(err.problem);
      return;
    }
    if (err.problem.status !== HttpStatus.UNPROCESSABLE_ENTITY || !err.problem.violations) {
      setProblem(err.problem);
      return;
    }
    // Map server-side violations onto the same RHF errors object client validation
    // populates, so both surface via `errors[name]?.message` with no parallel channel.
    let mappedAny = false;
    let unmappedExist = false;
    for (const violation of err.problem.violations) {
      const field = baseField(violation.field);
      if (isInviteFieldName(field)) {
        setError(field, { type: "server", message: violation.message });
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

  const submitInvitation = handleSubmit(async (values) => {
    try {
      const useCase = container.get<InviteUser>("BackOfficeInviteUser");
      // The password is the inviter's proof for an ADMIN grant and nothing else: any other invitation
      // leaves it out of the request entirely, whatever was typed before ADMIN was unchecked.
      await useCase.run(
        values.roles.includes(Role.ADMIN)
          ? { email: values.email, roles: values.roles, currentPassword: values.currentPassword }
          : { email: values.email, roles: values.roles },
      );
      setProblem(null);
      toastNotifier.success("Invitation sent", { description: values.email });
      router.push(safeHref(userRoutes.list));
      router.refresh();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      handleHttpError(err);
    }
  });

  // In-flight latch: `disabled` lands on the next React commit, so holding Enter in a field (or the retry
  // action) presents the same ADMIN password twice, and each attempt spends a unit of the per-identity
  // proof budget that is the only ceiling on guessing it from a live session.
  const submitting = useRef(false);
  const onSubmit = (event?: FormEvent<HTMLFormElement>) => {
    if (submitting.current) {
      event?.preventDefault();
      return;
    }
    submitting.current = true;
    // `handleSubmit` settles after a validation reject too, so releasing here rather than inside the async
    // body also frees a submit that never reached the use case.
    return submitInvitation(event).finally(() => {
      submitting.current = false;
    });
  };

  // Typed recovery in the persistent error surface, keyed off the problem type. Only the lost race
  // has an action the user can take from here; every other type carries none, so the surface stays
  // an explanation rather than offering a button that cannot help.
  const recoveryAction =
    problem?.type === SharedProblemType.CONCURRENT_UNIQUE_WRITE ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={isSubmitting}
        onClick={() => {
          // Fire-and-forget: the submit handler resolves every outcome internally.
          onSubmit();
        }}
        aria-label="Try again"
        title="Send the invitation again"
        data-testid="invite-user-form__conflict-retry"
      >
        Try again
      </Button>
    ) : undefined;

  return (
    <form
      onSubmit={onSubmit}
      className="invite-user-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="invite-user-form"
      data-hydrated={hydrated ? "true" : undefined}
      noValidate
    >
      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          action={recoveryAction}
          testId="invite-user-form__mutation-error"
        />
      ) : null}

      <FormField
        name="email"
        label="Email"
        required
        error={errors.email?.message}
        helper="The invited person receives an email to set their password and sign in."
      >
        <Input
          {...register("email")}
          type="email"
          autoComplete="off"
          // Dedicated invite route: the form is the whole page, so the first field is where the
          // user was already going. The route names itself through `metadata`, which is what
          // keeps the surface from being anonymous — but that naming does not precede the
          // focus, and the claim that it does would be wrong: `autoFocus` lands in the commit
          // phase while Next's route announcer is a passive effect, and on a direct load the
          // announcer stays silent by design. There is no edit counterpart to exclude.
          // eslint-disable-next-line jsx-a11y/no-autofocus
          autoFocus
          data-testid="invite-user-form__email"
        />
      </FormField>

      <fieldset
        className="invite-user-form__roles space-y-2"
        aria-describedby={errors.roles ? "invite-user-form__roles-error" : undefined}
      >
        <legend className="text-foreground text-sm font-medium">
          Roles <span className="text-destructive">*</span>
        </legend>
        <p className="text-muted-foreground text-xs">
          Choose at least one role for the new member.
        </p>
        <div className="grid gap-2 sm:grid-cols-2">
          {offeredRoles.map((role) => (
            <label
              key={role}
              className="border-border hover:bg-accent flex items-center gap-2 rounded-md border p-2 text-sm"
            >
              <input
                type="checkbox"
                value={role}
                {...register("roles")}
                className="border-input size-4 rounded"
                data-testid={`invite-user-form__role-${role}`}
              />
              {ROLE_LABEL[role]}
            </label>
          ))}
        </div>
        {errors.roles ? (
          <p
            id="invite-user-form__roles-error"
            role="alert"
            className="text-destructive text-sm"
            data-testid="invite-user-form__roles-error"
          >
            {errors.roles.message}
          </p>
        ) : null}
      </fieldset>

      {grantsAdmin ? (
        <FormField
          name="currentPassword"
          label="Your current password"
          required
          error={errors.currentPassword?.message}
          helper="Inviting an administrator asks for your password, so a borrowed session cannot create one."
        >
          <PasswordInput
            autoComplete="current-password"
            defaultRevealed={false}
            toggleTestId="invite-user-form__current-password-toggle"
            {...register("currentPassword", { shouldUnregister: true })}
            data-testid="invite-user-form__current-password"
          />
        </FormField>
      ) : null}

      <footer className="invite-user-form__footer flex flex-col-reverse items-stretch gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Link
          href={safeHref(userRoutes.list)}
          aria-disabled={isSubmitting || undefined}
          aria-label="Cancel and go back to users"
          title="Cancel and go back to users"
          tabIndex={isSubmitting ? -1 : undefined}
          onClick={(event) => {
            if (isSubmitting) event.preventDefault();
          }}
          className={cn(
            buttonVariants({ variant: "ghost", size: "sm" }),
            "w-full sm:w-auto",
            isSubmitting && "pointer-events-none opacity-50",
          )}
        >
          Cancel
        </Link>
        <Button
          type="submit"
          size="sm"
          disabled={isSubmitting || !hydrated}
          data-icon={isSubmitting ? "inline-start" : undefined}
          aria-label="Send invitation"
          title="Send invitation"
          className="w-full sm:w-auto"
          data-testid="invite-user-form__submit"
        >
          {isSubmitting ? (
            <>
              <Spinner className="size-3.5" testId="invite-user-form__submit-spinner" />
              Sending…
            </>
          ) : (
            "Send invitation"
          )}
        </Button>
      </footer>
    </form>
  );
}
