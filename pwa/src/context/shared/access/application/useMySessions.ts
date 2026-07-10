"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { SessionSummary } from "../domain/SessionSummary";
import type { SessionsRepository } from "../domain/SessionsRepository";

const SESSIONS_REPOSITORY_KEY = "SessionsRepository";

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

export interface MySessionsState {
  state: ViewStatus;
  sessions: SessionSummary[];
  problem: ProblemDetails | null;
  /** True when at least one session other than the current one can be revoked. */
  hasOtherSessions: boolean;
  revoking: boolean;
  revokeProblem: ProblemDetails | null;
  revokeOthers: () => Promise<void>;
  dismissRevokeProblem: () => void;
  reload: () => void;
}

/**
 * Loads the signed-in user's active sessions and exposes a "sign out other
 * devices" action that revokes every session but the current one, then refreshes
 * the list. Load and revoke failures are surfaced as RFC 9457 problems for the
 * UI to render; a revoke never touches the current session.
 */
export function useMySessions(): MySessionsState {
  const repository = (): SessionsRepository =>
    container.get<SessionsRepository>(SESSIONS_REPOSITORY_KEY);

  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [sessions, setSessions] = useState<SessionSummary[]>([]);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [revoking, setRevoking] = useState(false);
  const [revokeProblem, setRevokeProblem] = useState<ProblemDetails | null>(null);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const load = useCallback(async () => {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const result = await repository().list();
      if (!mountedRef.current) return;
      setSessions(result);
      setState(result.length === 0 ? ViewStatus.EMPTY : ViewStatus.READY);
    } catch (error) {
      if (!mountedRef.current) return;
      setProblem(toProblem(error));
      setState(ViewStatus.ERROR);
    }
    // repository() reads a container singleton; it is stable across renders.
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  const revokeOthers = useCallback(async () => {
    setRevoking(true);
    setRevokeProblem(null);
    try {
      await repository().revokeOthers();
      if (!mountedRef.current) return;
      await load();
    } catch (error) {
      if (!mountedRef.current) return;
      setRevokeProblem(toProblem(error));
    } finally {
      if (mountedRef.current) setRevoking(false);
    }
  }, [load]);

  const dismissRevokeProblem = useCallback(() => setRevokeProblem(null), []);
  const reload = useCallback(() => {
    void load();
  }, [load]);

  const hasOtherSessions = sessions.some((session) => !session.current);

  return {
    state,
    sessions,
    problem,
    hasOtherSessions,
    revoking,
    revokeProblem,
    revokeOthers,
    dismissRevokeProblem,
    reload,
  };
}
