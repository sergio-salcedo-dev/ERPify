"use client";

import { type FormEvent, useRef, useState, useSyncExternalStore } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { RecoverySecretRepository } from "@/context/shared/access/domain/RecoverySecretRepository";
import type { MintedRecoverySecret } from "@/context/shared/access/domain/RecoverySecret";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  MintRecoverySecretSchema,
  type MintRecoverySecretFormValues,
} from "@/context/backoffice/user/application/schemas/auth/MintRecoverySecretSchema";
import { FormField, Spinner } from "@/components/erpify";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { Button } from "@/components/ui/button";

const RECOVERY_SECRET_REPOSITORY_KEY = "RecoverySecretRepository";

/** The problem type the API hangs on the password field rather than on a `violations[]` entry. */
const INVALID_CURRENT_PASSWORD = "invalid-current-password";

// Stable no-op subscribe: the "has the client hydrated" flag never changes after the hydration
// commit, so `useSyncExternalStore` needs only its server (`false`) vs client (`true`) snapshots.
const emptySubscribe = () => () => {};

interface MintRecoverySecretFormProps {
  /** Hands the once-only plaintext straight to the surface that reveals it. */
  onMinted: (minted: MintedRecoverySecret) => void;
  /** Every failure this form cannot hang on its own field, for the owner's banner. */
  onProblem: (problem: ProblemDetails) => void;
}

/**
 * Mints the signed-in account's recovery secret, proving ownership with the current password.
 * It talks to the `RecoverySecretRepository` port and never inspects a status code: a wrong
 * password arrives as a problem `type` and is hung on the field the user typed it in, while
 * everything else — a second mint, a spent rate-limit budget — goes to the owner's persistent
 * banner with its correlation id.
 *
 * The plaintext is passed upward and never held here, so it lives in exactly one component's
 * state for exactly as long as that component shows it.
 */
export function MintRecoverySecretForm({
  onMinted,
  onProblem,
}: Readonly<MintRecoverySecretFormProps>) {
  // A failure that is not an `HttpError` is not this form's to explain — a broken container
  // binding is not a rejected password. Parking it here and re-throwing during render hands it
  // to the segment error boundary, the surface this application already built for "something
  // broke"; throwing from the submit callback would only reject a promise nobody awaits, and
  // the button would appear to do nothing.
  const [fatal, setFatal] = useState<unknown>(null);

  // Until React wires the submit handler, a native submit performs a GET that would put the
  // password into the URL, history and access logs. Gating the submit button on hydration stops
  // any submit — click or Enter — from firing that native GET.
  const hydrated = useSyncExternalStore(
    emptySubscribe,
    () => true,
    () => false,
  );

  // In-flight latch: `disabled` lands on the next React commit, so holding Enter in the password
  // field presents the same password twice before the button ever greys out. The second attempt
  // spends another unit of `password_change_per_identity` — the only ceiling on guessing this
  // password from a live session, and one that does not feed the persisted lockout.
  const submitting = useRef(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<MintRecoverySecretFormValues>(MintRecoverySecretSchema, {
    defaultValues: { currentPassword: "" },
  });

  const submitMint = handleSubmit(async (values) => {
    try {
      const secrets = container.get<RecoverySecretRepository>(RECOVERY_SECRET_REPOSITORY_KEY);
      onMinted(await secrets.mint(values.currentPassword));
    } catch (error) {
      if (!(error instanceof HttpError)) {
        setFatal(() => error);
        return;
      }
      const { problem } = error;
      if (problem.type === INVALID_CURRENT_PASSWORD) {
        setError(
          "currentPassword",
          { type: "server", message: problem.detail ?? problem.title },
          { shouldFocus: true },
        );
        return;
      }
      onProblem(problem);
    }
  });

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    if (submitting.current) {
      event.preventDefault();
      return;
    }
    submitting.current = true;
    // `handleSubmit`'s promise settles after a validation reject too, so releasing the latch here
    // rather than inside the async body also frees a submit that never reached the port.
    return submitMint(event).finally(() => {
      submitting.current = false;
    });
  };

  if (fatal !== null) throw fatal;

  return (
    <form
      onSubmit={onSubmit}
      className="mint-recovery-secret border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="mint-recovery-secret"
      data-hydrated={hydrated ? "true" : undefined}
      noValidate
    >
      <FormField
        name="currentPassword"
        label="Current password"
        required
        error={errors.currentPassword?.message}
        helper="Minting a recovery secret requires your password, so a borrowed session cannot create one."
      >
        <PasswordInput
          autoComplete="current-password"
          defaultRevealed={false}
          toggleTestId="mint-recovery-secret__current-password-toggle"
          {...register("currentPassword")}
          data-testid="mint-recovery-secret__current-password"
        />
      </FormField>

      <footer className="mint-recovery-secret__footer flex justify-end pt-2">
        <Button
          type="submit"
          size="sm"
          disabled={isSubmitting || !hydrated}
          data-icon={isSubmitting ? "inline-start" : undefined}
          aria-label="Create recovery secret"
          title="Create recovery secret"
          className="w-full sm:w-auto"
          data-testid="mint-recovery-secret__submit"
        >
          {isSubmitting ? (
            <>
              <Spinner className="size-3.5" testId="mint-recovery-secret__submit-spinner" />
              Creating…
            </>
          ) : (
            "Create recovery secret"
          )}
        </Button>
      </footer>
    </form>
  );
}
