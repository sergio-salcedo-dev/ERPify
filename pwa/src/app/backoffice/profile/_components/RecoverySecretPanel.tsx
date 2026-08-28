"use client";

import { useState, type ReactNode } from "react";
import { LifeBuoy, TriangleAlert } from "lucide-react";
import { AsyncBoundary, CopyButton, MutationError, StatusBadge } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { useRecoverySecret } from "@/context/shared/access/application/useRecoverySecret";
import type {
  MintedRecoverySecret,
  RecoverySecretStatus,
} from "@/context/shared/access/domain/RecoverySecret";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { MintRecoverySecretForm } from "./MintRecoverySecretForm";

/** An account holds at most one secret; a second mint is refused with this problem type. */
const RECOVERY_SECRET_ALREADY_EXISTS = "recovery-secret-already-exists";

const NEVER_SHOWN_AGAIN =
  "Recovery secret created. Copy it now — this is the only time it will ever be shown.";

/**
 * The signed-in account's recovery secret: whether one exists, when it was minted, when it
 * expires, and the two actions that change that. It is the only way back in if the account is
 * ever locked out, because this installation ships with one administrator and no shell.
 *
 * The plaintext lives in this component's state for the length of the confirmation view and
 * nowhere else — not storage, not a URL, not a link. Leaving that view discards it, which is
 * why the view says so before the user can leave it.
 */
export function RecoverySecretPanel() {
  const {
    state,
    status,
    problem,
    revoking,
    revokeProblem,
    revoke,
    applyMinted,
    dismissRevokeProblem,
    reload,
  } = useRecoverySecret();
  const [minted, setMinted] = useState<MintedRecoverySecret | null>(null);
  const [mintProblem, setMintProblem] = useState<ProblemDetails | null>(null);

  const handleMinted = (value: MintedRecoverySecret): void => {
    setMintProblem(null);
    setMinted(value);
    applyMinted(value);
  };

  // A 409 means the account already holds a secret and this surface's read is simply behind —
  // re-reading swaps the mint form for the revoke-then-mint view the API is describing, so the
  // user is not left pressing a button that can never succeed.
  const handleMintProblem = (value: ProblemDetails): void => {
    setMintProblem(value);
    if (value.type === RECOVERY_SECRET_ALREADY_EXISTS) reload();
  };

  const renderCurrent = (current: RecoverySecretStatus): ReactNode => {
    if (minted) {
      return <MintedSecretNotice minted={minted} onAcknowledge={() => setMinted(null)} />;
    }
    if (current.exists) {
      return (
        <ExistingSecret
          mintedAt={current.mintedAt}
          expiresAt={current.expiresAt}
          revoking={revoking}
          onRevoke={revoke}
        />
      );
    }
    return <MintRecoverySecretForm onMinted={handleMinted} onProblem={handleMintProblem} />;
  };

  return (
    <section
      className="recovery-secret flex flex-col gap-3"
      aria-labelledby="recovery-secret__title"
      data-testid="recovery-secret"
    >
      <h2
        id="recovery-secret__title"
        className="text-foreground flex items-center gap-2 text-lg font-semibold"
        data-testid="recovery-secret__title"
      >
        <LifeBuoy className="size-4" aria-hidden="true" />
        Recovery secret
      </h2>
      <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">
        A second credential you keep somewhere safe. If you are ever locked out of this account, it
        is what gets you back in — so store it away from the device you sign in with.
      </p>

      {mintProblem ? (
        <MutationError
          problem={mintProblem}
          onDismiss={() => setMintProblem(null)}
          testId="recovery-secret__mint-error"
        />
      ) : null}

      {revokeProblem ? (
        <MutationError
          problem={revokeProblem}
          onDismiss={dismissRevokeProblem}
          testId="recovery-secret__revoke-error"
        />
      ) : null}

      <AsyncBoundary
        state={state}
        data={status ?? undefined}
        error={problem ?? undefined}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={reload}
            aria-label="Retry loading the recovery secret"
            title="Retry loading the recovery secret"
            data-testid="recovery-secret__retry"
          >
            Try again
          </Button>
        }
      >
        {renderCurrent}
      </AsyncBoundary>
    </section>
  );
}

/**
 * The one and only reveal. The secret is selectable text beside a copy control rather than a
 * link or a download, so nothing about it ever reaches a URL, and the warning sits above it
 * rather than below: it has to be read before the value is acted on, not after.
 */
function MintedSecretNotice({
  minted,
  onAcknowledge,
}: Readonly<{ minted: MintedRecoverySecret; onAcknowledge: () => void }>) {
  return (
    <div
      className="recovery-secret__minted border-border bg-card space-y-3 rounded-lg border p-4"
      data-testid="recovery-secret__minted"
    >
      <output className="recovery-secret__minted-title text-foreground flex items-center gap-2 text-sm font-medium">
        <span
          className="recovery-secret__minted-dot bg-success size-2 shrink-0 rounded-full"
          aria-hidden="true"
        />
        {NEVER_SHOWN_AGAIN}
      </output>

      <div className="recovery-secret__value-row flex flex-wrap items-center gap-2">
        <code
          className="recovery-secret__value border-border bg-muted text-foreground min-w-0 flex-1 rounded-md border px-2 py-1.5 font-mono text-xs break-all select-all"
          data-testid="recovery-secret__value"
        >
          {minted.secret}
        </code>
        <CopyButton
          value={minted.secret}
          label="Copy secret"
          copiedLabel="Secret copied"
          title="Copy the recovery secret to the clipboard"
          testId="recovery-secret__copy"
        />
      </div>

      <p className="text-muted-foreground text-sm leading-relaxed">
        Keep it in a password manager, or on paper somewhere only you can reach. It is not stored
        anywhere you can read it back, and nobody can recover it for you.
      </p>

      <SecretInstants mintedAt={minted.mintedAt} expiresAt={minted.expiresAt} />

      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onAcknowledge}
        aria-label="I have saved my recovery secret"
        title="Hide the recovery secret"
        data-testid="recovery-secret__acknowledge"
      >
        I have saved it
      </Button>
    </div>
  );
}

/** The account holds a secret: what is known about it, and the only action left — destroying it. */
function ExistingSecret({
  mintedAt,
  expiresAt,
  revoking,
  onRevoke,
}: Readonly<{
  mintedAt: string;
  expiresAt: string;
  revoking: boolean;
  onRevoke: () => Promise<void>;
}>) {
  return (
    <div
      className="recovery-secret__existing border-border bg-card space-y-3 rounded-lg border p-4"
      data-testid="recovery-secret__existing"
    >
      <StatusBadge variant="success" label="Active" testId="recovery-secret__state" />
      <SecretInstants mintedAt={mintedAt} expiresAt={expiresAt} />
      <p className="text-muted-foreground text-sm leading-relaxed">
        An account holds one recovery secret at a time. To replace this one, revoke it first and
        then create another.
      </p>
      <RevokeRecoverySecretDialog revoking={revoking} onRevoke={onRevoke} />
    </div>
  );
}

/**
 * Both instants, always together. The expiry is not decoration: this secret outlives most of
 * the passwords around it, and its expiry is the one way the channel closes with nobody acting,
 * so a holder who was never shown the date cannot plan around it.
 */
function SecretInstants({
  mintedAt,
  expiresAt,
}: Readonly<{ mintedAt: string; expiresAt: string }>) {
  return (
    <dl className="recovery-secret__instants grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
      <div className="recovery-secret__instant">
        <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
          Created
        </dt>
        <dd className="text-foreground mt-1" data-testid="recovery-secret__minted-at">
          {dateTimeProvider.formatIsoToLocalDateTime(mintedAt)}
        </dd>
      </div>
      <div className="recovery-secret__instant">
        <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
          Expires
        </dt>
        <dd className="text-foreground mt-1" data-testid="recovery-secret__expires-at">
          {dateTimeProvider.formatIsoToLocalDateTime(expiresAt)}
        </dd>
      </div>
    </dl>
  );
}

/**
 * Revoking destroys the account's only recovery edge, and nothing about the surface it sits on
 * warns of that, so the confirmation carries the consequence rather than the verb. It takes no
 * credential — the live session is the proof — which is exactly why it asks twice.
 */
function RevokeRecoverySecretDialog({
  revoking,
  onRevoke,
}: Readonly<{ revoking: boolean; onRevoke: () => Promise<void> }>) {
  const [open, setOpen] = useState(false);

  const confirm = async (): Promise<void> => {
    await onRevoke();
    setOpen(false);
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        render={
          <Button
            type="button"
            variant="destructive"
            size="sm"
            aria-label="Revoke recovery secret"
            title="Revoke recovery secret"
            data-testid="recovery-secret__revoke"
          >
            Revoke
          </Button>
        }
      />
      <DialogContent className="sm:max-w-md" data-testid="recovery-secret__revoke-dialog">
        <DialogHeader>
          <div className="flex items-start gap-3">
            <span
              className="bg-destructive/10 text-destructive flex size-10 shrink-0 items-center justify-center rounded-full"
              aria-hidden="true"
            >
              <TriangleAlert className="size-5" />
            </span>
            <div className="flex flex-1 flex-col gap-2">
              <DialogTitle className="text-lg">Revoke recovery secret</DialogTitle>
              <DialogDescription className="text-base leading-relaxed">
                The secret you saved stops working immediately, and this account keeps no other way
                back in until you create a new one. This cannot be undone.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <DialogFooter>
          <DialogClose
            render={
              <Button
                variant="ghost"
                disabled={revoking}
                aria-label="Keep the recovery secret"
                title="Keep the recovery secret"
              >
                Cancel
              </Button>
            }
          />
          <Button
            variant="destructive"
            disabled={revoking}
            onClick={() => {
              void confirm();
            }}
            aria-label="Revoke it"
            title="Revoke it"
            data-testid="recovery-secret__revoke-confirm"
          >
            Revoke it
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
