"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { PageEnvelope } from "@/context/shared/search/domain";
import type { AuditEntry } from "../domain/AuditEntry";
import type {
  AuditTimelineCriteria,
  AuditTimelineNavigator,
  AuditTimelineRepository,
} from "../domain/AuditTimelineRepository";

/** DI binding identifiers — kept in lock-step with the audit bindings in `Container.ts`. */
const REPOSITORY_KEY = "BackOfficeAuditTimelineRepository";
const NAVIGATOR_KEY = "BackOfficeAuditTimelineNavigator";

export interface AuditTimelineState {
  state: ViewStatus;
  entries: AuditEntry[];
  pagination: PageEnvelope | null;
  paginationActions: { hasPrev: boolean; hasNext: boolean; goPrev: () => void; goNext: () => void };
  problem: ProblemDetails | null;
  reload: () => void;
}

export interface UseAuditTimelineConfig {
  criteria: AuditTimelineCriteria;
  /** Whether any filter is active — distinguishes a true empty log from filtered-to-zero. */
  hasActiveFilter: boolean;
}

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

/**
 * Lean, read-only keyset list hook for the audit timeline. A deliberate sibling of the generic
 * `useResourceList`, stripped to what a forensic read needs: two load paths (first-page criteria
 * search vs verbatim link follow), a monotonic request guard so a slow response never overwrites a
 * newer one, cursor-reset on criteria change, empty-tail recovery, and a derived boundary state.
 * No selection, bulk, optimistic delete, or peek — Backoffice never mutates auditoría (D5), so a
 * `CrudRepository` would be a wrong (ISP-violating) dependency here.
 */
export function useAuditTimeline({
  criteria,
  hasActiveFilter,
}: UseAuditTimelineConfig): AuditTimelineState {
  const repository = (): AuditTimelineRepository =>
    container.get<AuditTimelineRepository>(REPOSITORY_KEY);
  const navigator = (): AuditTimelineNavigator =>
    container.get<AuditTimelineNavigator>(NAVIGATOR_KEY);

  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [pagination, setPagination] = useState<PageEnvelope | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const reloadingRef = useRef(false);
  // The server-issued link that produced the current view, or null for the first page (a plain
  // query). The link is an opaque transport token, forwarded verbatim and never parsed.
  const [activeLink, setActiveLink] = useState<string | null>(null);
  // Monotonic request token: a slow in-flight response whose token is stale is discarded.
  const seqRef = useRef(0);
  // Recovery links already followed since the last non-empty load — guards the empty-tail recovery
  // against re-issuing the same follow() if the server keeps returning an empty tail page.
  const recoveryLinksRef = useRef<Set<string>>(new Set());

  // A stable key for the criteria so the load effect and cursor-reset key off the *value*, not the
  // object identity the caller rebuilds each render.
  const criteriaKey = useMemo(() => JSON.stringify(criteria), [criteria]);

  const loadItems = useCallback(async () => {
    const seq = ++seqRef.current;
    setState(ViewStatus.LOADING);
    setProblem(null);
    reloadingRef.current = true;
    try {
      const result =
        activeLink === null
          ? await repository().search(criteria)
          : await navigator().follow(activeLink);
      if (!mountedRef.current || seq !== seqRef.current) return;
      if (result.entries.length > 0) recoveryLinksRef.current.clear();
      setEntries(result.entries);
      setPagination({
        hasNext: result.hasNext,
        hasPrev: result.hasPrev,
        count: result.count,
        links: result.links,
      });
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mountedRef.current || seq !== seqRef.current) return;
      const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
      setProblem(err instanceof HttpError ? err.problem : genericProblem(fallbackDetail));
      setState(ViewStatus.ERROR);
    } finally {
      if (seq === seqRef.current) reloadingRef.current = false;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [criteriaKey, activeLink]);

  const navigateTo = useCallback((link: string) => setActiveLink(link), []);

  // Gate the affordance on the link being present, not just the flag: a control is enabled only when
  // there is a cursor to follow (the D-A11y keyset contract disables on a null link).
  const hasPrev = (pagination?.hasPrev ?? false) && pagination?.links.prev != null;
  const hasNext = (pagination?.hasNext ?? false) && pagination?.links.next != null;
  const goPrev = useCallback(() => {
    if (reloadingRef.current) return;
    const link = pagination?.links.prev;
    if (link) navigateTo(link);
  }, [pagination, navigateTo]);
  const goNext = useCallback(() => {
    if (reloadingRef.current) return;
    const link = pagination?.links.next;
    if (link) navigateTo(link);
  }, [pagination, navigateTo]);

  // Recover from an emptied page beyond the first: follow a remaining step so the user lands on real
  // rows instead of the filtered-to-zero panel.
  useEffect(() => {
    if (state !== ViewStatus.READY || entries.length > 0 || reloadingRef.current) return;
    const recoveryLink = pagination?.links.prev ?? pagination?.links.next;
    if (!recoveryLink || recoveryLinksRef.current.has(recoveryLink)) return;
    recoveryLinksRef.current.add(recoveryLink);
    navigateTo(recoveryLink);
  }, [state, entries.length, pagination, navigateTo]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadItems();
  }, [loadItems]);

  // Reset to the first page when the criteria change, dropping the stale cursor before the next
  // request. loadItems then re-runs (its identity changes with criteriaKey).
  const [prevCriteriaKey, setPrevCriteriaKey] = useState(criteriaKey);
  if (criteriaKey !== prevCriteriaKey) {
    setPrevCriteriaKey(criteriaKey);
    setActiveLink(null);
  }

  // Derived boundary state: an empty page with no active filter and no nav affordance reads as
  // first-run empty; otherwise it stays READY (the page renders filtered-to-zero inside READY).
  const boundaryState = useMemo<ViewStatus>(() => {
    if (state === ViewStatus.LOADING || state === ViewStatus.ERROR) return state;
    const hasNavAffordance = (pagination?.hasPrev ?? false) || (pagination?.hasNext ?? false);
    return entries.length === 0 && !hasActiveFilter && !hasNavAffordance
      ? ViewStatus.EMPTY
      : ViewStatus.READY;
  }, [state, entries.length, hasActiveFilter, pagination]);

  return {
    state: boundaryState,
    entries,
    pagination,
    paginationActions: { hasPrev, hasNext, goPrev, goNext },
    problem,
    reload: loadItems,
  };
}
