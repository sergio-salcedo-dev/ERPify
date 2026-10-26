"use client";

import {
  createContext,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { useSession } from "../../application/useSession";
import type { RecoverySecretStatus } from "../../domain/RecoverySecret";
import {
  RECOVERY_SECRET_REPOSITORY_KEY,
  type RecoverySecretRepository,
} from "../../domain/RecoverySecretRepository";
import {
  UNKNOWN_RECOVERY_READINESS,
  recoveryReadinessOf,
  type RecoveryReadiness,
} from "../../domain/RecoveryReadiness";
import { Role } from "../../domain/Role";
import { UserStatus } from "../../domain/UserStatus";

export interface RecoveryReadinessContextValue {
  readiness: RecoveryReadiness;
  /**
   * Fold a status the caller already holds — the 201 of a mint, the 204 of a revoke, a read it
   * made itself — into the shell's verdict, so the shell never needs a second request to catch
   * up with a mutation made on another surface.
   */
  publish: (status: RecoverySecretStatus) => void;
}

export const RecoveryReadinessContext = createContext<RecoveryReadinessContextValue | null>(null);

/**
 * The back-office shell's one read of the caller's recovery secret, and the place every later
 * answer about it is folded into.
 *
 * It lives in a provider rather than in the layout because the App Router does not remount a
 * layout on navigation: a read taken once there would still say "no secret" after the owner
 * minted one on the profile page. The profile surface publishes what its mutation already
 * proved instead, so neither a second GET after a mutation nor one per navigation is needed.
 *
 * It asks only for an ACTIVE administrator, the one audience the warning is for; any other
 * session stays `unknown`, and so does a read in flight or one that failed — a failure is
 * silent here because the shell is not the surface that owes an explanation (the profile panel
 * keeps its own error). Each read and publish takes a ticket, and only the latest may land: a
 * slow shell read answering after a mint must not reinstate the "none" it was sent to learn.
 */
export function RecoveryReadinessProvider({ children }: Readonly<{ children: ReactNode }>) {
  const { session } = useSession();
  const subject =
    session?.user.status === UserStatus.ACTIVE && session.roles.includes(Role.ADMIN)
      ? session.user.id
      : null;

  const [readiness, setReadiness] = useState<RecoveryReadiness>(UNKNOWN_RECOVERY_READINESS);
  const ticketRef = useRef(0);
  // The audience the verdict is for, readable from `publish` without re-creating it per session.
  const subjectRef = useRef<string | null>(subject);

  useEffect(() => {
    subjectRef.current = subject;
    const ticket = ++ticketRef.current;
    // A different subject (or none) never inherits the previous one's verdict.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setReadiness(UNKNOWN_RECOVERY_READINESS);
    if (subject === null) return;

    container
      .get<RecoverySecretRepository>(RECOVERY_SECRET_REPOSITORY_KEY)
      .read()
      .then(
        (status) => {
          if (ticket === ticketRef.current) setReadiness(recoveryReadinessOf(status));
        },
        () => {
          // Unknown, not "not live": an outage is no reason to tell anyone they hold nothing.
        },
      );
    return () => {
      // A superseded subject's answer lands nowhere.
      ticketRef.current += 1;
    };
  }, [subject]);

  // A no-op outside the audience: a surface any session can open (the profile panel) must not set
  // a verdict the provider keeps `unknown` for everyone but an active administrator.
  const publish = useCallback((status: RecoverySecretStatus): void => {
    if (subjectRef.current === null) return;
    ticketRef.current += 1;
    setReadiness(recoveryReadinessOf(status));
  }, []);

  const value = useMemo<RecoveryReadinessContextValue>(
    () => ({ readiness, publish }),
    [readiness, publish],
  );

  return (
    <RecoveryReadinessContext.Provider value={value}>{children}</RecoveryReadinessContext.Provider>
  );
}
