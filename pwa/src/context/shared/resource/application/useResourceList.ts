"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { uuidV7 } from "@/lib/uuidV7";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { PageEnvelope } from "@/context/shared/domain/Search";
import type { CrudRepository, ResourceSearchCriteria } from "../domain/CrudRepository";
import type { ResourceSearchNavigator } from "../domain/ResourceSearchNavigator";
import type { QueryState } from "./createQueryState";

/**
 * Persistent delete-error state for the list (one error per mutation origin): a
 * new attempt's failure replaces it, a success clears it, a dismiss closes it.
 * `itemId` is the row whose delete failed (drives neighbor focus after a
 * stale-404 refresh); `scope` decides where focus lands after recovery.
 */
export interface DeleteErrorState {
  problem: ProblemDetails;
  itemId: string;
  scope: "single" | "bulk";
}

export interface UseResourceListConfig<T extends { id: string }, F> {
  repositoryKey: string;
  navigatorKey: string;
  query: QueryState<F>;
  toCriteria: (filter: F, sort: QueryState<F>["sort"], limit: number) => ResourceSearchCriteria;
  hasActiveFilter: (filter: F) => boolean;
  /** Human label for an item, used in delete toasts. */
  getLabel: (item: T) => string;
  /** Singular entity noun for toast copy (e.g. "user"). */
  entitySingular: string;
  /** Plural entity noun for toast copy (e.g. "users"). */
  entityPlural: string;
  /** Row testids to focus after an optimistic delete (visible one wins). */
  rowFocusTestIds?: (id: string) => string[];
  /** Testid of the bulk-bar delete control, focused after a bulk-error refresh. */
  bulkDeleteTestId?: string;
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

function isNotFoundError(reason: unknown): boolean {
  return reason instanceof HttpError && reason.problem.status === HttpStatus.NOT_FOUND;
}

function capitalize(word: string): string {
  return word.length === 0 ? word : word[0].toUpperCase() + word.slice(1);
}

/**
 * Generalized list state machine for a cursor-only, server-driven list. Drives
 * two load paths (criteria search vs verbatim link follow), a monotonic request
 * guard, query-reset-on-change, derived boundary state, page selection,
 * optimistic single + bulk delete (with existence re-probe), record peek,
 * empty-page recovery, and focus/announcement management. There is no realtime
 * channel of its own; callers that drive their own reconciliation use
 * `silentReload` (manual background refresh) and `markDeleted` (tombstone an
 * out-of-band deletion so the bulk restore won't resurrect it).
 */
export function useResourceList<T extends { id: string }, F, TInput>(
  config: UseResourceListConfig<T, F>,
) {
  const { query } = config;
  const repository = (): CrudRepository<T, TInput> =>
    container.get<CrudRepository<T, TInput>>(config.repositoryKey);
  const navigator = (): ResourceSearchNavigator<T> =>
    container.get<ResourceSearchNavigator<T>>(config.navigatorKey);

  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [items, setItems] = useState<T[]>([]);
  const [pagination, setPagination] = useState<PageEnvelope | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set());
  const [deleteError, setDeleteError] = useState<DeleteErrorState | null>(null);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const reloadingRef = useRef(false);
  // The server-issued link that produced the current view, or null for the
  // first page (a plain query). State, not a ref: navigation re-runs the load
  // effect by changing it, and the query-change reset clears it during render.
  // The link is an opaque transport token forwarded verbatim, never parsed.
  const [activeLink, setActiveLink] = useState<string | null>(null);
  // Monotonic request token: a slow in-flight response whose token is no longer
  // current is discarded, so a debounced filter + a page click never let an
  // older result overwrite a newer one.
  const seqRef = useRef(0);
  // Ids this client has seen deleted since the last successful load — consulted
  // by the bulk restore so a row deleted mid-flight stays gone. Pruned on every
  // successful load except while a bulk delete is in flight.
  const deletedIdsRef = useRef<Set<string>>(new Set());
  const bulkDeleteInFlightRef = useRef(false);
  // Recovery links already followed since the last non-empty load — guards the
  // empty-page recovery against re-issuing the same follow() if the server keeps
  // returning an empty tail page (the in-memory mock clamps the cursor, a real
  // API may not). Cleared whenever a load yields rows.
  const recoveryLinksRef = useRef<Set<string>>(new Set());

  const { filter, sort, pageSize } = query;

  const loadItems = useCallback(
    async (options?: { silent?: boolean }) => {
      const seq = ++seqRef.current;
      if (!options?.silent) {
        setState(ViewStatus.LOADING);
        setProblem(null);
      }
      reloadingRef.current = true;
      try {
        const criteria = config.toCriteria(filter, sort, pageSize);
        // Two load paths: a fresh criteria search (first page of a query) or a
        // verbatim follow of a server-issued link. The link is opaque and
        // self-contained, so the navigator needs nothing but the link itself.
        const result =
          activeLink === null
            ? await repository().search(criteria)
            : await navigator().follow(activeLink);
        if (!mountedRef.current || seq !== seqRef.current) return;
        if (!bulkDeleteInFlightRef.current) {
          deletedIdsRef.current = new Set();
        }
        if (result.items.length > 0) recoveryLinksRef.current.clear();
        setItems(result.items);
        setPagination({
          hasNext: result.hasNext,
          hasPrev: result.hasPrev,
          count: result.count,
          links: result.links,
        });
        // Selection is scoped to the visible page: ids absent from the new
        // result drop out, so the bulk count never counts rows the user cannot see.
        setSelectedIds((prev) => {
          if (prev.size === 0) return prev;
          const live = new Set(result.items.map((item) => item.id));
          const next = new Set([...prev].filter((id) => live.has(id)));
          return next.size === prev.size ? prev : next;
        });
        setState(ViewStatus.READY);
      } catch (err) {
        if (!mountedRef.current || options?.silent || seq !== seqRef.current) return;
        const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
        const nextProblem = err instanceof HttpError ? err.problem : genericProblem(fallbackDetail);
        setProblem(nextProblem);
        setState(ViewStatus.ERROR);
      } finally {
        if (seq === seqRef.current) reloadingRef.current = false;
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [filter, sort, pageSize, activeLink],
  );

  const navigateTo = useCallback((link: string) => {
    setActiveLink(link);
  }, []);

  // Cursor navigation lives here, not at each call site: resolving the prev/next
  // envelope link and following it is derived knowledge the hook already owns
  // (it holds `pagination` and `navigateTo`). Consumers get ready affordances;
  // the per-entity pagination components stay pure representation.
  //
  // The in-flight guard mirrors the recovery effect: until a load resolves and
  // replaces the envelope, `pagination` still holds the current page's links, so
  // a second click would re-follow the same cursor (skipping/repeating a page) or
  // race the empty-page recovery navigation. Ignoring clicks while loading keeps
  // one step per settled page.
  const hasPrev = pagination?.hasPrev ?? false;
  const hasNext = pagination?.hasNext ?? false;
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

  // Recover from an emptied page beyond the first: follow a remaining step so
  // the user lands on real rows instead of the filtered-to-zero panel.
  useEffect(() => {
    if (state !== ViewStatus.READY || items.length > 0 || reloadingRef.current) return;
    const recoveryLink = pagination?.links.prev ?? pagination?.links.next;
    if (!recoveryLink || recoveryLinksRef.current.has(recoveryLink)) return;
    recoveryLinksRef.current.add(recoveryLink);

    navigateTo(recoveryLink);
  }, [state, items.length, pagination, navigateTo]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadItems();
  }, [loadItems]);

  // Derived boundary state: an empty page with no active filter and no nav
  // affordance reads as first-run empty; otherwise it stays READY.
  const boundaryState = useMemo<ViewStatus>(() => {
    if (state === ViewStatus.LOADING || state === ViewStatus.ERROR) return state;
    const hasNavAffordance = (pagination?.hasPrev ?? false) || (pagination?.hasNext ?? false);
    return items.length === 0 && !config.hasActiveFilter(filter) && !hasNavAffordance
      ? ViewStatus.EMPTY
      : ViewStatus.READY;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state, items.length, filter, pagination]);

  // Reset to the first page when the query changes, adjusting state during
  // render — dropping the active link discards the stale cursor before the next
  // request. loadItems then re-runs (its identity changes with these deps).
  const [prevFilter, setPrevFilter] = useState(filter);
  const [prevSort, setPrevSort] = useState(sort);
  const [prevPageSize, setPrevPageSize] = useState(pageSize);
  if (filter !== prevFilter || sort !== prevSort || pageSize !== prevPageSize) {
    setPrevFilter(filter);
    setPrevSort(sort);
    setPrevPageSize(pageSize);
    setActiveLink(null);
  }

  // After an optimistic delete removes the active row, focus moves to the
  // neighbor instead of falling back to <body>.
  const pendingFocusIdRef = useRef<string | null>(null);
  const pendingBulkFocusRef = useRef(false);
  const listContainerRef = useRef<HTMLDivElement>(null);
  const [refreshAnnouncement, setRefreshAnnouncement] = useState("");

  const deleteItem = (id: string): void => {
    deletedIdsRef.current.add(id);
    const deleted = items.find((item) => item.id === id);
    toastNotifier.success(
      `${capitalize(config.entitySingular)} deleted`,
      deleted ? { description: config.getLabel(deleted) } : undefined,
    );
    setDeleteError(null);
    const index = items.findIndex((item) => item.id === id);
    const neighbor = index === -1 ? undefined : (items[index + 1] ?? items[index - 1]);
    pendingFocusIdRef.current = neighbor && neighbor.id !== id ? neighbor.id : null;
    setItems((prev) => prev.filter((item) => item.id !== id));
    setSelectedIds((prev) => {
      if (!prev.has(id)) return prev;
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  const deleteFailed = useCallback((id: string, failure: ProblemDetails): void => {
    setDeleteError({ problem: failure, itemId: id, scope: "single" });
  }, []);

  const handleDeleteErrorRefresh = async (): Promise<void> => {
    const current = deleteError;
    if (!current) return;
    if (current.scope === "single") {
      const index = items.findIndex((item) => item.id === current.itemId);
      const neighbor = index === -1 ? undefined : (items[index + 1] ?? items[index - 1]);
      pendingFocusIdRef.current = neighbor && neighbor.id !== current.itemId ? neighbor.id : null;
    } else {
      pendingBulkFocusRef.current = true;
    }
    setDeleteError(null);
    setRefreshAnnouncement("");
    await loadItems();
    if (current.scope === "single") {
      setRefreshAnnouncement("List refreshed");
    }
  };

  useEffect(() => {
    const id = pendingFocusIdRef.current;
    if (!id) return;
    pendingFocusIdRef.current = null;
    const selectors = config.rowFocusTestIds?.(globalThis.CSS.escape(id)) ?? [];
    const rows =
      selectors.length > 0
        ? globalThis.document.querySelectorAll<HTMLElement>(
            selectors.map((testId) => `[data-testid="${testId}"]`).join(", "),
          )
        : globalThis.document.querySelectorAll<HTMLElement>("[data-no-focus-target]");
    const row = [...rows].find((el) => el.offsetParent !== null) ?? rows[0];
    if (row) {
      row.focus();
    } else {
      listContainerRef.current?.focus();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [items]);

  const toggleSelect = useCallback((id: string): void => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  const clearSelection = useCallback((): void => setSelectedIds(new Set()), []);

  // Tombstone an id deleted out-of-band (e.g. a realtime `deleted` event): record
  // it so the bulk restore won't resurrect a row deleted mid-flight, and drop it
  // from the selection. It does not refetch — callers pair it with `silentReload`.
  const markDeleted = useCallback((id: string): void => {
    deletedIdsRef.current.add(id);
    setSelectedIds((prev) => {
      if (!prev.has(id)) return prev;
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  }, []);

  // Esc clears the selection when no transient layer is open.
  useEffect(() => {
    const el = listContainerRef.current;
    if (!el) return;
    const handleKeyDown = (event: globalThis.KeyboardEvent): void => {
      if (event.key !== KeyboardKey.ESCAPE || event.defaultPrevented) return;
      setSelectedIds((prev) => (prev.size > 0 ? new Set<string>() : prev));
    };
    el.addEventListener("keydown", handleKeyDown);
    return () => el.removeEventListener("keydown", handleKeyDown);
  }, []);

  useEffect(() => {
    if (!pendingBulkFocusRef.current) return;
    if (state === ViewStatus.ERROR) {
      pendingBulkFocusRef.current = false;
      return;
    }
    if (state !== ViewStatus.READY) return;
    pendingBulkFocusRef.current = false;
    if (selectedIds.size > 0 && config.bulkDeleteTestId) {
      globalThis.document
        .querySelector<HTMLElement>(`[data-testid="${config.bulkDeleteTestId}"]`)
        ?.focus();
    } else {
      listContainerRef.current?.focus();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state, selectedIds, items]);

  // Selection announcements: the polite region is always mounted and rapid
  // changes coalesce into the final count.
  const [selectionAnnouncement, setSelectionAnnouncement] = useState("");
  const prevSelectionCountRef = useRef(0);
  useEffect(() => {
    const count = selectedIds.size;
    const previous = prevSelectionCountRef.current;
    prevSelectionCountRef.current = count;
    if (count === previous) return;
    const timer = setTimeout(() => {
      setSelectionAnnouncement(count === 0 ? "Selection cleared" : `${count} selected`);
    }, 400);
    return () => clearTimeout(timer);
  }, [selectedIds]);

  // Record peek: a peeked row deleted meanwhile already unmounts the drawer
  // (it derives from `items`); clearing the id keeps it from reopening.
  const [peekId, setPeekId] = useState<string | null>(null);
  useEffect(() => {
    if (peekId === null || items.some((item) => item.id === peekId)) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setPeekId(null);
    listContainerRef.current?.focus();
  }, [items, peekId]);

  const handleBulkDelete = async (): Promise<void> => {
    if (bulkDeleteInFlightRef.current) return;
    const ids = [...selectedIds].filter((id) => items.some((item) => item.id === id));
    if (ids.length === 0) return;
    bulkDeleteInFlightRef.current = true;
    try {
      await runBulkDelete(ids);
    } finally {
      bulkDeleteInFlightRef.current = false;
    }
  };

  const runBulkDelete = async (ids: string[]): Promise<void> => {
    const repo = repository();
    const probes = await Promise.allSettled(ids.map((id) => repo.find(id)));
    if (!mountedRef.current) return;
    const staleIndex = probes.findIndex(
      (probe) => probe.status === "rejected" && isNotFoundError(probe.reason),
    );
    if (staleIndex !== -1) {
      const stale = probes[staleIndex] as PromiseRejectedResult;
      setDeleteError({
        problem: (stale.reason as HttpError).problem,
        itemId: ids[staleIndex],
        scope: "bulk",
      });
      toastNotifier.error(`Couldn't delete ${config.entityPlural} — see error details`);
      return;
    }

    const snapshot = items;
    const removing = new Set(ids);
    setItems((prev) => prev.filter((item) => !removing.has(item.id)));
    setSelectedIds(new Set());

    const results = await Promise.allSettled(ids.map((id) => repo.delete(id)));
    if (!mountedRef.current) return;

    const rejections = ids.flatMap((id, index) => {
      const result = results[index];
      if (result.status === "fulfilled") deletedIdsRef.current.add(id);
      return result.status === "rejected" ? [{ id, reason: result.reason as unknown }] : [];
    });
    const succeeded = ids.length - rejections.length;
    if (succeeded > 0) {
      toastNotifier.success(
        `${succeeded} ${succeeded === 1 ? config.entitySingular : config.entityPlural} deleted`,
      );
    }
    if (rejections.length === 0) {
      setDeleteError(null);
      return;
    }
    const first = rejections[0];
    const fallbackDetail = first.reason instanceof Error ? first.reason.message : "Unknown error";
    setDeleteError({
      problem:
        first.reason instanceof HttpError ? first.reason.problem : genericProblem(fallbackDetail),
      itemId: first.id,
      scope: "bulk",
    });
    toastNotifier.error(`Some ${config.entityPlural} could not be deleted`, {
      description: `${rejections.length} of ${ids.length} could not be deleted. See error details.`,
    });

    const restorableIds = rejections
      .filter(({ reason }) => !isNotFoundError(reason))
      .map(({ id }) => id);
    if (restorableIds.length === 0) return;
    const reprobes = await Promise.allSettled(restorableIds.map((id) => repo.find(id)));
    if (!mountedRef.current) return;
    const confirmed = new Set(
      restorableIds.filter((_, index) => {
        const reprobe = reprobes[index];
        return reprobe.status === "fulfilled" || !isNotFoundError(reprobe.reason);
      }),
    );
    for (const id of deletedIdsRef.current) confirmed.delete(id);
    if (confirmed.size === 0) return;
    const restored = snapshot.filter((item) => confirmed.has(item.id));
    setItems((prev) => {
      const present = new Set(prev.map((item) => item.id));
      return [...prev, ...restored.filter((item) => !present.has(item.id))];
    });
    setSelectedIds((prev) => new Set([...prev, ...confirmed]));
  };

  // A coalesced silent reconcile: skip while a load or bulk delete is in flight.
  const silentReload = (): void => {
    if (reloadingRef.current || bulkDeleteInFlightRef.current) return;
    loadItems({ silent: true });
  };

  return {
    rawState: state,
    state: boundaryState,
    items,
    pagination,
    paginationActions: { hasPrev, hasNext, goPrev, goNext },
    problem,
    selectedIds,
    setSelectedIds,
    toggleSelect,
    clearSelection,
    markDeleted,
    navigateTo,
    reload: loadItems,
    silentReload,
    deleteItem,
    deleteFailed,
    deleteError,
    setDeleteError,
    handleDeleteErrorRefresh,
    peekId,
    setPeekId,
    bulkDelete: handleBulkDelete,
    listContainerRef,
    selectionAnnouncement,
    refreshAnnouncement,
  };
}
