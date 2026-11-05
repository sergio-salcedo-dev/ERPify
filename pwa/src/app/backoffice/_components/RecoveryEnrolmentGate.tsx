"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { LifeBuoy } from "lucide-react";
import { MutationError } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { useCanRole } from "@/context/shared/access/application/useCan";
import { useRecoveryReadiness } from "@/context/shared/access/application/useRecoveryReadiness";
import { useRecoverySecret } from "@/context/shared/access/application/useRecoverySecret";
import type { MintedRecoverySecret } from "@/context/shared/access/domain/RecoverySecret";
import { Role } from "@/context/shared/access/domain/Role";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { isSessionRedeemed } from "@/context/shared/access/infrastructure/redeemedSession";
import { MintRecoverySecretForm } from "../profile/_components/MintRecoverySecretForm";
import { MintedSecretNotice } from "../profile/_components/RecoverySecretPanel";

/** An account holds at most one secret; a second mint is refused with this problem type. */
const RECOVERY_SECRET_ALREADY_EXISTS = "recovery-secret-already-exists";

interface RecoveryEnrolmentGateProps {
  children: ReactNode;
  /** The shell's own sign-out, so the single-flight and departure bookkeeping around it stay in one place. */
  onSignOut: () => void;
  signingOut: boolean;
}

/**
 * Stands between an administrator and the back office until the account holds a recovery secret.
 *
 * A sole administrator with no secret is one forgotten password or one email-keyed lockout away from an
 * account nobody can open and nothing can repair, and a standing banner is the thing such an account was
 * already free to ignore. So the step replaces the page content, on every route, and has no skip: the
 * mint form it hosts is the same one the profile page uses, which re-proves the current password, and the
 * moment a mint succeeds the shell's verdict flips to live and the step stands down.
 *
 * It blocks on exactly one verdict: a KNOWN `not-live` with reason `none`, for an ADMIN. Three cases stay
 * open on purpose.
 * - `unknown` (the read is in flight or failed): "we could not tell" is no grounds to lock anyone out.
 * - `expired`: a lapsed row still occupies the single slot, so a mint here could only fail with a 409; the
 *   standing banner sends the owner to the profile, where the row can be revoked first.
 * - A session opened by REDEEMING a recovery secret: the secret was just spent, so the account holds none,
 *   but minting needs the password and that owner may not have it. They keep the banner, not the wall.
 *
 * The secret just minted is revealed here, once, before the content opens: unmounting the step the instant
 * the verdict flipped would discard the only copy of a credential shown exactly once. The reveal is held in
 * this component's state — not storage, not a URL — and leaving it discards it.
 */
export function RecoveryEnrolmentGate({
  children,
  onSignOut,
  signingOut,
}: Readonly<RecoveryEnrolmentGateProps>) {
  const isAdmin = useCanRole(Role.ADMIN);
  const { readiness } = useRecoveryReadiness();
  const [minted, setMinted] = useState<MintedRecoverySecret | null>(null);

  if (minted) {
    return <MintedReveal minted={minted} onAcknowledge={() => setMinted(null)} />;
  }

  const blocking =
    isAdmin && readiness.kind === "not-live" && readiness.reason === "none" && !isSessionRedeemed();
  if (!blocking) return <>{children}</>;

  return <EnrolmentStep onMinted={setMinted} onSignOut={onSignOut} signingOut={signingOut} />;
}

/**
 * The frame both views share. Its heading takes the focus when the view replaces the page, so the
 * change is not silent: a navigation moves the focus to `<main>`, and this is what then names what is
 * in it.
 */
function EnrolmentFrame({
  heading,
  children,
}: Readonly<{ heading: ReactNode; children: ReactNode }>) {
  const headingRef = useRef<HTMLHeadingElement | null>(null);
  useEffect(() => {
    headingRef.current?.focus({ preventScroll: true });
  }, []);

  return (
    <section
      className="recovery-enrolment mx-auto flex max-w-xl flex-col gap-4 p-4 md:p-8"
      aria-labelledby="recovery-enrolment__title"
      data-testid="recovery-enrolment"
    >
      <h1
        id="recovery-enrolment__title"
        ref={headingRef}
        tabIndex={-1}
        className="text-foreground flex items-center gap-2 text-xl font-semibold outline-none"
        data-testid="recovery-enrolment__title"
      >
        {heading}
      </h1>
      {children}
    </section>
  );
}

function MintedReveal({
  minted,
  onAcknowledge,
}: Readonly<{ minted: MintedRecoverySecret; onAcknowledge: () => void }>) {
  return (
    <EnrolmentFrame heading="Your recovery secret is ready">
      <MintedSecretNotice minted={minted} onAcknowledge={onAcknowledge} />
    </EnrolmentFrame>
  );
}

function EnrolmentStep({
  onMinted,
  onSignOut,
  signingOut,
}: Readonly<{
  onMinted: (minted: MintedRecoverySecret) => void;
  onSignOut: () => void;
  signingOut: boolean;
}>) {
  const { applyMinted, reload } = useRecoverySecret();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  // `applyMinted` folds the 201 into the shell's verdict, which is what stands the step down; the reveal is
  // handed up because this component is about to unmount.
  const handleMinted = (value: MintedRecoverySecret): void => {
    setProblem(null);
    applyMinted(value);
    onMinted(value);
  };

  // A 409 means the account already holds a secret and the shell's read is simply behind: re-reading folds
  // the truth into the verdict, which stands the step down without the owner pressing a button that can
  // never succeed.
  const handleProblem = (value: ProblemDetails): void => {
    setProblem(value);
    if (value.type === RECOVERY_SECRET_ALREADY_EXISTS) reload();
  };

  return (
    <EnrolmentFrame
      heading={
        <>
          <LifeBuoy className="size-5" aria-hidden="true" />
          Create your recovery secret to continue
        </>
      }
    >
      <p className="text-foreground text-sm leading-relaxed">
        Your account has no recovery secret. As an administrator you may be the only person who can
        open this back office, so if you are ever locked out — a forgotten password, a locked
        account — nothing else can get you back in, and nobody can recover the account for you.
      </p>
      <p className="text-muted-foreground text-sm leading-relaxed">
        Create the secret now, while you can still sign in. It is shown once: keep it in a password
        manager, or on paper somewhere only you can reach. Creating it asks for your current
        password.
      </p>

      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          testId="recovery-enrolment__mint-error"
        />
      ) : null}

      <MintRecoverySecretForm onMinted={handleMinted} onProblem={handleProblem} />

      <div className="recovery-enrolment__signout border-border flex flex-col gap-2 border-t pt-4">
        <p className="text-muted-foreground text-sm leading-relaxed">
          Don&apos;t remember your password? Sign out, use Forgot password on the sign-in page, then
          sign in again and create your secret.
        </p>
        <div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={signingOut}
            onClick={onSignOut}
            aria-label="Sign out"
            title="Sign out"
            data-testid="recovery-enrolment__signout"
          >
            {signingOut ? "Signing out…" : "Sign out"}
          </Button>
        </div>
      </div>
    </EnrolmentFrame>
  );
}
