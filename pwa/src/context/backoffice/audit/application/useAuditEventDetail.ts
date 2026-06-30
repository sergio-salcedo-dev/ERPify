"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { AuditEventDetail } from "../domain/AuditChange";
import type { AuditEventDetailRepository } from "../domain/AuditEventDetailRepository";

/** DI binding identifier — kept in lock-step with the audit bindings in `Container.ts`. */
const REPOSITORY_KEY = "BackOfficeAuditEventDetailRepository";

export interface AuditEventDetailState {
  detail: AuditEventDetail | null;
  loading: boolean;
}

/**
 * Lean, read-only fetch of one audit event's detail (the diff) when a timeline row opens. Deliberately
 * a sibling of `useAuditTimeline`, not part of it: the detail is a separate resource, lifted on demand
 * so the keyset list stays slim. A monotonic request guard discards a slow response superseded by a
 * newer open; a failed read leaves `detail` null (the drawer falls back to its dormant slot) — the
 * diff is a secondary read and never blocks the slim row already on screen.
 */
export function useAuditEventDetail(id: string | null): AuditEventDetailState {
  const [detail, setDetail] = useState<AuditEventDetail | null>(null);
  const [loading, setLoading] = useState(false);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const seqRef = useRef(0);

  const load = useCallback(async (entryId: string | null) => {
    const seq = ++seqRef.current;
    if (entryId === null) {
      setDetail(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setDetail(null);
    // Fire-and-forget read: the awaited work lives in this block-bodied async function, never the
    // effect's return slot, so React treats the effect as synchronous and never awaits the promise.
    try {
      const repository = container.get<AuditEventDetailRepository>(REPOSITORY_KEY);
      const result = await repository.findById(entryId);
      if (!mountedRef.current || seq !== seqRef.current) return;
      setDetail(result);
    } catch {
      if (!mountedRef.current || seq !== seqRef.current) return;
      setDetail(null);
    } finally {
      if (mountedRef.current && seq === seqRef.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load(id);
  }, [id, load]);

  return { detail, loading };
}
