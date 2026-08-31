"use client";

import { type FormEvent, useId, useRef, useState, useSyncExternalStore } from "react";
import { useRouter } from "next/navigation";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  RedeemRecoverySecretSchema,
  type RedeemRecoverySecretFormValues,
} from "@/context/backoffice/user/application/schemas/auth/RedeemRecoverySecretSchema";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { RedeemRecoverySecretRepository } from "@/context/backoffice/user/domain/RedeemRecoverySecretRepository";
import {
  RedeemRecoverySecretOutcomeKind,
  type RedeemRecoverySecretOutcome,
} from "@/context/backoffice/user/domain/RedeemRecoverySecretOutcome";
import { FormField } from "@/components/erpify";
import { PasswordInput } from "@/components/ui/PasswordInput";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { useOnlineStatus } from "@/context/shared/connectivity/infrastructure/useOnlineStatus";
import { ConnectivityButton } from "@/context/shared/connectivity/infrastructure/ui/ConnectivityButton";
import { OfflineNotice } from "@/context/shared/connectivity/infrastructure/ui/OfflineNotice";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";

const REDEEM_REPOSITORY_KEY = "BackOfficeRedeemRecoverySecretRepository";
const SECRET_FIELD = "secret";

// The one message for every way a presentation can die. The API answers a single opaque 400
// across malformed, unknown, expired, already spent and budget exhausted, so anything more
// specific here would be the client inventing a distinction the wire refuses to make — and
// each of those distinctions is a bit of information handed to whoever is guessing.
const INVALID_SECRET_MESSAGE =
  "That recovery secret did not work. Check you copied all of it, then try again.";

// Stable no-op subscribe: the "has the client hydrated" flag never changes after the hydration
// commit, so `useSyncExternalStore` needs only its server (`false`) vs client (`true`) snapshots.
const emptySubscribe = () => () => {};

/**
 * Signs a locked-out user back in with the recovery secret they minted. The secret is typed or
 * pasted into the field and read from nowhere else — never `?secret=`, never a link: a query
 * string reaches browser history, the `Referer` header and every access log in front of the
 * app, and this credential stays valid for ten years.
 *
 * A dead secret keeps the form on screen with one message, because a typo and a spent secret
 * are indistinguishable here and the typo is the recoverable one. A non-active account is the
 * opposite — no amount of retrying helps — so it gets the matching terminal wall.
 */
export function RecoveryRedeemForm() {
  const router = useRouter();
  const { login } = useSession();
  const online = useOnlineStatus();
  const purposeId = useId();
  const [wall, setWall] = useState<AccessWallVariant | null>(null);
  const [invalidSecret, setInvalidSecret] = useState(false);
  const [requestFailed, setRequestFailed] = useState(false);
  // The redemption COMMITTED and the confirming probe did not. Distinct from `requestFailed`
  // because the recovery is the opposite one: the secret is spent and un-presentable, so
  // "try again" walks the user into the opaque refusal for a credential that already worked.
  const [sessionUnconfirmed, setSessionUnconfirmed] = useState(false);

  // Until React wires the submit handler, a native submit performs a GET that would put the
  // secret in the URL, the history entry and the access log — the one place this flow exists to
  // keep it out of. Gating the button on hydration stops any submit, click or Enter, from
  // firing that native GET.
  const hydrated = useSyncExternalStore(
    emptySubscribe,
    () => true,
    () => false,
  );

  // In-flight latch: ConnectivityButton disables only the button, so pressing Enter in the field
  // while a submit runs would present the same secret twice. The second presentation spends
  // another unit of the redemption budget that exists to make guessing expensive, and the loser
  // of the race would tap its invalid message over the success.
  const submitting = useRef(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<RedeemRecoverySecretFormValues>(RedeemRecoverySecretSchema, {
    defaultValues: { secret: "" },
  });

  const submitRedeem = handleSubmit(async (values) => {
    setInvalidSecret(false);
    setRequestFailed(false);
    setSessionUnconfirmed(false);
    const repo = container.get<RedeemRecoverySecretRepository>(REDEEM_REPOSITORY_KEY);
    let outcome: RedeemRecoverySecretOutcome;
    try {
      outcome = await repo.redeem(values.secret);
    } catch {
      // A network/transport failure, or a session store that could not answer, is not a redeem
      // outcome; surface a neutral retryable error rather than blaming the secret.
      setRequestFailed(true);
      return;
    }

    if (outcome.kind === RedeemRecoverySecretOutcomeKind.REDEEMED) {
      // The 204 has set the httpOnly session cookie; re-probe `/me` so the AuthProvider is
      // authenticated before leaving, otherwise RequireAuth bounces the stale state straight
      // back out. An unconfirmed probe is reported rather than announced over: the secret is
      // already spent, so a false "Signed in" costs the user the one credential that was left.
      if (!(await login())) {
        // `login()` answers `null` for any probe failure, transport included — so this branch is
        // reached with the session cookie already set and the one-use secret already spent.
        // Reporting it as a retryable failure sends the account's only credential holder back to
        // a field that can now only produce the opaque refusal, while they are one navigation
        // away from being inside. The sibling reset flow states this same class in `AccessWall`;
        // here the consequence is worse, because the credential cannot be presented twice.
        setSessionUnconfirmed(true);
        return;
      }
      toastNotifier.success("Signed in");
      // A static in-app destination, never one read from the URL: this page takes no
      // parameters, and a redeem is not a deep link anybody was interrupted on.
      router.push(safeHref(Routes.BACKOFFICE));
      return;
    }
    if (outcome.kind === RedeemRecoverySecretOutcomeKind.SUSPENDED) {
      setWall(AccessWallVariant.SUSPENDED);
      return;
    }
    if (outcome.kind === RedeemRecoverySecretOutcomeKind.DEACTIVATED) {
      setWall(AccessWallVariant.DEACTIVATED);
      return;
    }
    setInvalidSecret(true);
  });

  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    if (submitting.current) {
      event.preventDefault();
      return;
    }
    submitting.current = true;
    // handleSubmit's promise settles after a validation reject too, so releasing the latch here
    // (rather than inside the async body) also frees a submit that never reached the port.
    return submitRedeem(event).finally(() => {
      submitting.current = false;
    });
  };

  if (wall) {
    return <AccessWall variant={wall} />;
  }

  return (
    <form
      onSubmit={onSubmit}
      className="space-y-4"
      noValidate
      aria-describedby={purposeId}
      data-testid="recovery-redeem-form"
      data-hydrated={hydrated ? "true" : undefined}
    >
      <h1 className="text-foreground text-xl font-semibold">Use your recovery secret</h1>
      <p id={purposeId} className="text-muted-foreground text-sm">
        Paste the recovery secret you saved when you set it up. It signs you back in on this device.
      </p>
      {invalidSecret ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="recovery-redeem-form__invalid"
        >
          {INVALID_SECRET_MESSAGE}
        </p>
      ) : null}
      {requestFailed ? (
        <p
          role="alert"
          className="text-danger-strong text-sm"
          data-testid="recovery-redeem-form__error"
        >
          Something went wrong. Please try again.
        </p>
      ) : null}
      {sessionUnconfirmed ? (
        <p
          role="alert"
          className="text-warning-strong text-sm"
          data-testid="recovery-redeem-form__unconfirmed"
        >
          Your recovery secret was accepted and has now been used, but we could not confirm the
          session. Do not re-enter it — it only works once. Open the back office to continue.{" "}
          <button
            type="button"
            className="underline underline-offset-2"
            onClick={() => router.push(safeHref(Routes.BACKOFFICE))}
            aria-label="Open the back office"
            title="Open the back office"
            data-testid="recovery-redeem-form__continue"
          >
            Open the back office
          </button>
        </p>
      ) : null}
      <FormField
        name={SECRET_FIELD}
        label="Recovery secret"
        required
        error={errors.secret?.message}
      >
        <PasswordInput
          autoComplete="off"
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          defaultRevealed
          toggleTestId="recovery-redeem-form__secret-toggle"
          {...register(SECRET_FIELD)}
          data-testid="recovery-redeem-form__secret"
        />
      </FormField>
      {online ? null : <OfflineNotice testId="recovery-redeem-form__offline" />}
      <ConnectivityButton
        loading={isSubmitting}
        disabled={!online || !hydrated}
        testId="recovery-redeem-form__submit"
      >
        Sign in
      </ConnectivityButton>
    </form>
  );
}
