<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * The bound on how much audit work a refused recovery request can be made to do. One window's slot per
 * address: the first caller to claim it writes the row, every later one in the same window is suppressed.
 *
 * It is a CLAIM and not a question, which is why it is not spelled as one. Asking whether a slot is free and
 * then taking it are separate acts only in appearance: split across two calls they leave a window in which
 * every concurrent caller reads "free" and writes a row, which is the amplification this exists to stop.
 *
 * The claim is granted or refused on the DECISION to write, never on a write that came back confirmed. That
 * is the opposite of how a notification seal is ordered ({@see SendAccountLockedEmailBestEffort} stamps only
 * after a delivery that succeeded, so an SMTP fault cannot buy silence) and the inversion is deliberate: what
 * this budget bounds is WORK, and a failed `audit_log` INSERT costs the same round-trip as a successful one.
 * Waiting for confirmation would hand the full amplifier back precisely while the table is unavailable. The
 * price — an outage can cost one window of silence per address — is paid by the caller's warning line.
 */
interface RecoveryThrottleAuditBudget
{
    /**
     * Takes this window's slot for the address if it is still free, and reports whether the caller now owns
     * it. Refusal carries no meaning beyond "already observed": it is never an error and never reaches the
     * response.
     */
    public function claimFor(string $email): bool;
}
