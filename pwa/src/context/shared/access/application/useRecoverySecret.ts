"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { MintedRecoverySecret, RecoverySecretStatus } from "../domain/RecoverySecret";
import {
  RECOVERY_SECRET_REPOSITORY_KEY,
  type RecoverySecretRepository,
} from "../domain/RecoverySecretRepository";
import { useRecoveryReadinessPublisher } from "./useRecoveryReadiness";

function genericProblem(detail: string): ProblemDetails {
  return {
    type: "about:blank",
    title: "Unexpected error",
    status: 0,
    detail,
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

function toProblem(error: unknown): ProblemDetails {
  if (error instanceof HttpError) return error.problem;
  return genericProblem(error instanceof Error ? error.message : "Unknown error");
}

export interface RecoverySecretState {
  state: ViewStatus;
  /** `null` only while the first read is in flight or has failed. */
  status: RecoverySecretStatus | null;
  problem: ProblemDetails | null;
  revoking: boolean;
  revokeProblem: ProblemDetails | null;
  /** Destroying the secret is proved with the current password, never with the session alone. */
  revoke: (currentPassword: string) => Promise<void>;
  /** Fold a just-minted secret's instants into the read state without a second round trip. */
  applyMinted: (minted: MintedRecoverySecret) => void;
  dismissRevokeProblem: () => void;
  reload: () => void;
}

/**
 * Reads whether the signed-in identity holds a recovery secret and exposes the revoke that
 * destroys it. Minting is deliberately absent: its rejection belongs on the form field the
 * password was typed into, so it stays with that form rather than being laundered through
 * shared state. The revoke takes the credential too — it is the caller's to collect and this
 * hook's only to pass through, never to hold.
 *
 * A successful mint is folded in through {@link RecoverySecretState.applyMinted} rather than
 * re-reading: the 201 already carries the authoritative instants, so a second GET would ask
 * the server to repeat itself.
 *
 * Every status this hook learns — its own read, a revoke, a mint — is also published to the
 * back-office shell's readiness warning, which is how that warning leaves the moment a secret
 * is minted here without the shell asking again. A just-minted secret is live and a revoked
 * one is gone; both are what the server's own answer just said, not a clock reading.
 */
export function useRecoverySecret(): RecoverySecretState {
  const repository = (): RecoverySecretRepository =>
    container.get<RecoverySecretRepository>(RECOVERY_SECRET_REPOSITORY_KEY);

  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [status, setStatus] = useState<RecoverySecretStatus | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [revoking, setRevoking] = useState(false);
  const [revokeProblem, setRevokeProblem] = useState<ProblemDetails | null>(null);
  const publish = useRecoveryReadinessPublisher();
  // Read through a ref so the callbacks below stay stable across renders.
  const publishRef = useRef(publish);
  useEffect(() => {
    publishRef.current = publish;
  }, [publish]);

  const learn = useCallback((next: RecoverySecretStatus): void => {
    setStatus(next);
    publishRef.current(next);
  }, []);

  // Monotonic request token. `mountedRef` answers "is this component still here"; it says nothing
  // about ORDER, so with two reads in flight — a retry pressed twice, or the 409's `reload()`
  // overtaking the mount's own read — whichever RESOLVES last wins, not whichever was ISSUED last.
  // A stale answer can then reinstate `exists: true` over a state the user has already moved past.
  const requestRef = useRef(0);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const load = useCallback(async () => {
    const token = ++requestRef.current;
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const result = await repository().read();
      if (!mountedRef.current || token !== requestRef.current) return;
      learn(result);
      // "No secret yet" is a state this surface renders (it offers the mint), never an empty
      // state — an `EmptyState` here would replace the one control the user came for.
      setState(ViewStatus.READY);
    } catch (error) {
      if (!mountedRef.current || token !== requestRef.current) return;
      setProblem(toProblem(error));
      setState(ViewStatus.ERROR);
    }
    // repository() reads a container singleton; it is stable across renders.
  }, [learn]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  const revoke = useCallback(async (currentPassword: string) => {
    setRevoking(true);
    setRevokeProblem(null);
    try {
      await repository().revoke(currentPassword);
      const gone: RecoverySecretStatus = {
        exists: false,
        live: false,
        mintedAt: null,
        expiresAt: null,
      };
      // Published even if this surface has gone: the secret is gone either way, and the shell
      // outlives the page that revoked it.
      publishRef.current(gone);
      if (!mountedRef.current) return;
      setStatus(gone);
    } catch (error) {
      if (!mountedRef.current) return;
      setRevokeProblem(toProblem(error));
    } finally {
      if (mountedRef.current) setRevoking(false);
    }
  }, []);

  const applyMinted = useCallback(
    (minted: MintedRecoverySecret) => {
      learn({ exists: true, live: true, mintedAt: minted.mintedAt, expiresAt: minted.expiresAt });
    },
    [learn],
  );

  const dismissRevokeProblem = useCallback(() => setRevokeProblem(null), []);
  const reload = useCallback(() => {
    load();
  }, [load]);

  return {
    state,
    status,
    problem,
    revoking,
    revokeProblem,
    revoke,
    applyMinted,
    dismissRevokeProblem,
    reload,
  };
}
