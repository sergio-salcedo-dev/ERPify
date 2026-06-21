"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import type { CrudRepository } from "../domain/CrudRepository";

/** Single-item fetch lifecycle for detail/edit pages. */
export function useResourceItem<T, TInput>(repositoryKey: string, id: string) {
  const [item, setItem] = useState<T | null>(null);
  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  // Monotonic request token: when `id` changes, a slower earlier fetch must not
  // overwrite the result of the newer one (out-of-order resolution would show
  // the wrong item).
  const seqRef = useRef(0);

  const load = useCallback(async () => {
    const seq = ++seqRef.current;
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const result = await container.get<CrudRepository<T, TInput>>(repositoryKey).find(id);
      if (!mounted.current || seq !== seqRef.current) return;
      setItem(result);
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mounted.current || seq !== seqRef.current) return;
      setProblem(err instanceof HttpError ? err.problem : null);
      setState(ViewStatus.ERROR);
    }
  }, [repositoryKey, id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  return { item, state, problem, reload: load };
}
