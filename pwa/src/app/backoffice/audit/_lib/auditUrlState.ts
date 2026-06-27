"use client";

import { useCallback, useMemo } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { AuditView, EMPTY_AUDIT_FILTER, isAuditView, type AuditFilter } from "./auditFilter";

const VIEW_PARAM = "view";
const DIR_PARAM = "dir";
const ENTRY_PARAM = "entry";

/** Filter axes, in a fixed order so the serialized URL is deterministic and the memo key is stable. */
const FILTER_KEYS: ReadonlyArray<keyof AuditFilter> = [
  "level",
  "from",
  "to",
  "actorType",
  "actorId",
  "resourceType",
  "resourceId",
  "action",
  "correlationId",
];

export interface AuditUrlState {
  filter: AuditFilter;
  view: AuditView;
  direction: SortDirection;
  entry: string | null;
  setFilter: (next: AuditFilter) => void;
  patchFilter: (patch: Partial<AuditFilter>) => void;
  setView: (view: AuditView) => void;
  setDirection: (direction: SortDirection) => void;
  openEntry: (id: string) => void;
  closeEntry: () => void;
  reset: () => void;
}

/**
 * Single source of truth for the audit screen's state, held entirely in URL params (shareable,
 * never PII in storage — an `actor_id`/`resource_id` would be PII). Reads decode the params; writes
 * re-serialize the whole decoded state, so a stale param can never linger. Defaults (empty filters,
 * Timeline view, DESC, no open entry) are omitted from the URL to keep it minimal and the memo key
 * stable — the latter matters because the timeline hook resets its keyset cursor whenever the filter
 * value changes, so a flapping identity would thrash pagination.
 */
export function useAuditUrlState(): AuditUrlState {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const paramsKey = searchParams.toString();

  const filter = useMemo<AuditFilter>(() => {
    const params = new URLSearchParams(paramsKey);
    const read = (key: keyof AuditFilter): string => params.get(key)?.trim() ?? "";
    return {
      level: read("level"),
      from: read("from"),
      to: read("to"),
      actorType: read("actorType"),
      actorId: read("actorId"),
      resourceType: read("resourceType"),
      resourceId: read("resourceId"),
      action: read("action"),
      correlationId: read("correlationId"),
    };
  }, [paramsKey]);

  const view = useMemo<AuditView>(() => {
    const value = new URLSearchParams(paramsKey).get(VIEW_PARAM) ?? "";
    return isAuditView(value) ? value : AuditView.Timeline;
  }, [paramsKey]);

  const direction = useMemo<SortDirection>(() => {
    return new URLSearchParams(paramsKey).get(DIR_PARAM) === SortDirection.ASC
      ? SortDirection.ASC
      : SortDirection.DESC;
  }, [paramsKey]);

  const entry = useMemo<string | null>(() => {
    const value = new URLSearchParams(paramsKey).get(ENTRY_PARAM)?.trim();
    return value ? value : null;
  }, [paramsKey]);

  const commit = useCallback(
    (next: AuditFilter, nextView: AuditView, nextDir: SortDirection, nextEntry: string | null) => {
      const params = new URLSearchParams();
      for (const key of FILTER_KEYS) {
        const value = next[key].trim();
        if (value) params.set(key, value);
      }
      if (nextView !== AuditView.Timeline) params.set(VIEW_PARAM, nextView);
      if (nextDir !== SortDirection.DESC) params.set(DIR_PARAM, nextDir);
      if (nextEntry) params.set(ENTRY_PARAM, nextEntry);
      const qs = params.toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname],
  );

  // A filter change drops the open entry: the focused row may leave the page, so the drawer closes
  // rather than stranding a stale detail.
  const setFilter = useCallback(
    (next: AuditFilter) => commit(next, view, direction, null),
    [commit, view, direction],
  );
  const patchFilter = useCallback(
    (patch: Partial<AuditFilter>) => commit({ ...filter, ...patch }, view, direction, null),
    [commit, filter, view, direction],
  );
  const setView = useCallback(
    (next: AuditView) => commit(filter, next, direction, entry),
    [commit, filter, direction, entry],
  );
  const setDirection = useCallback(
    (next: SortDirection) => commit(filter, view, next, entry),
    [commit, filter, view, entry],
  );
  const openEntry = useCallback(
    (id: string) => commit(filter, view, direction, id),
    [commit, filter, view, direction],
  );
  const closeEntry = useCallback(
    () => commit(filter, view, direction, null),
    [commit, filter, view, direction],
  );
  const reset = useCallback(
    () => commit(EMPTY_AUDIT_FILTER, view, direction, null),
    [commit, view, direction],
  );

  return {
    filter,
    view,
    direction,
    entry,
    setFilter,
    patchFilter,
    setView,
    setDirection,
    openEntry,
    closeEntry,
    reset,
  };
}
