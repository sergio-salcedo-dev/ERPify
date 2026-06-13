"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { ViewStatus } from "@/context/shared/domain/types/status";
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

  const load = useCallback(async () => {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const result = await container.get<CrudRepository<T, TInput>>(repositoryKey).find(id);
      if (!mounted.current) return;
      setItem(result);
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mounted.current) return;
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
