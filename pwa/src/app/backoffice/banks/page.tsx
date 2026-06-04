"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBanks } from "@/context/backoffice/bank/application/SearchBanks";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { BankProblemType } from "@/context/backoffice/bank/domain/BankProblemType";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpStatus } from "@/context/shared/domain/types/http";
import {
  AsyncBoundary,
  DensityToggle,
  LIST_DENSITY_STORAGE_KEY,
  MutationError,
  SelectionMode,
  isListDensity,
} from "@/components/erpify";
import type { DataTableSelection, ListDensity } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { uuidV7 } from "@/lib/uuidV7";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { BanksTable } from "./_components/BanksTable";
import { BanksCards } from "./_components/BanksCards";
import { BanksStackedList } from "./_components/BanksStackedList";
import { BanksFilters } from "./_components/BanksFilters";
import { BanksPagination } from "./_components/BanksPagination";
import { BanksViewToggle, type BanksView } from "./_components/BanksViewToggle";
import { BanksListSkeleton } from "./_components/BanksListSkeleton";
import { BanksEmptyFiltered } from "./_components/BanksEmptyFiltered";
import { BULK_DELETE_TESTID, BanksBulkBar } from "./_components/BanksBulkBar";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  applyFilters,
  applySort,
  type BanksFilter,
  type BanksSort,
} from "./_lib/banksFilterSort";
import { BANKS_PAGE_SIZE_DEFAULT, type BanksPageSize, paginate } from "./_lib/paginate";
import { bankRoutes } from "./_lib/bankRoutes";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { countRecentlyCreated } from "./_lib/bankRecency";
import { bankTopics, useBankRealtime } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { useStoredPreference } from "@/lib/useStoredPreference";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";

type State = ViewStatus;

const BANKS_VIEW_STORAGE_KEY = "erpify:banks-view";
const DEFAULT_VIEW: BanksView = "table";

function isBanksView(value: unknown): value is BanksView {
  return value === "table" || value === "cards";
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
 * Persistent delete-error state for this page (one error per mutation origin):
 * a new attempt's failure replaces it, a success clears it, a dismiss closes
 * it. `bankId` is the row whose delete failed (drives neighbor focus after a
 * stale-404 refresh); `scope` decides where focus lands after recovery.
 */
interface DeleteErrorState {
  problem: ProblemDetails;
  bankId: string;
  scope: "single" | "bulk";
}

function isNotFoundError(reason: unknown): boolean {
  return reason instanceof HttpError && reason.problem.status === HttpStatus.NOT_FOUND;
}

export default function BanksListPage() {
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [banks, setBanks] = useState<Bank[]>([]);
  const [nextCursor, setNextCursor] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [filter, setFilter] = useState<BanksFilter>(EMPTY_FILTER);
  const [sort, setSort] = useState<BanksSort>(DEFAULT_SORT);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState<BanksPageSize>(BANKS_PAGE_SIZE_DEFAULT);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set());
  const [deleteError, setDeleteError] = useState<DeleteErrorState | null>(null);
  // Hydration-safe persisted preferences: SSR and first client paint always
  // render the defaults; the stored values apply after hydration.
  const [view, setView] = useStoredPreference<BanksView>(
    BANKS_VIEW_STORAGE_KEY,
    DEFAULT_VIEW,
    isBanksView,
  );
  const [density, setDensity] = useStoredPreference<ListDensity>(
    LIST_DENSITY_STORAGE_KEY,
    "compact",
    isListDensity,
  );

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const reloadingRef = useRef(false);

  const loadBanks = useCallback(async (options?: { silent?: boolean }) => {
    // A silent reconcile (e.g. after a realtime reconnect) refreshes in the
    // background: no LOADING skeleton flash, and a transient failure leaves the
    // current list in place instead of swapping in an error screen.
    if (!options?.silent) {
      setState(ViewStatus.LOADING);
      setProblem(null);
    }
    reloadingRef.current = true;
    try {
      const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
      const result = await useCase.run();
      if (!mountedRef.current) return;
      setBanks(result.banks);
      setNextCursor(result.nextCursor);
      // A reload recalculates the selection: ids that no longer exist on the
      // server would otherwise survive as phantoms inflating the bulk count.
      setSelectedIds((prev) => {
        if (prev.size === 0) return prev;
        const live = new Set(result.banks.map((bank) => bank.id));
        const next = new Set([...prev].filter((id) => live.has(id)));
        return next.size === prev.size ? prev : next;
      });
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mountedRef.current || options?.silent) return;
      const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
      const nextProblem = err instanceof HttpError ? err.problem : genericProblem(fallbackDetail);
      setProblem(nextProblem);
      setState(ViewStatus.ERROR);
    } finally {
      reloadingRef.current = false;
    }
  }, []);

  useEffect(() => {
    // loadBanks resets state to LOADING before its first await; that initial
    // setState is intentional (it also drives the Retry path) and runs through
    // a stable callback, so the cascading-render warning does not apply here.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadBanks();
  }, [loadBanks]);

  const visibleBanks = useMemo(
    () => applySort(applyFilters(banks, filter), sort),
    [banks, filter, sort],
  );

  // `state` tracks only the load lifecycle (loading / ready / error). Whether a
  // settled list reads as first-run empty or ready is purely a function of how
  // many banks remain, so derive it instead of caching a flag that goes stale
  // when a delete empties the list — otherwise the now-empty list keeps its
  // stale "ready" state and falls through to the filtered-to-zero panel even
  // with no active filter.
  const boundaryState = useMemo<State>(() => {
    if (state === ViewStatus.LOADING || state === ViewStatus.ERROR) return state;
    return banks.length === 0 ? ViewStatus.EMPTY : ViewStatus.READY;
  }, [state, banks.length]);

  const recentCount = useMemo(
    () =>
      countRecentlyCreated(
        banks.map((bank) => bank.createdAt),
        dateTimeProvider,
      ),
    [banks],
  );

  // Reset page when filters, sort or pageSize change (adjusting state during render)
  const [prevFilter, setPrevFilter] = useState(filter);
  const [prevSort, setPrevSort] = useState(sort);
  const [prevPageSize, setPrevPageSize] = useState(pageSize);
  if (filter !== prevFilter || sort !== prevSort || pageSize !== prevPageSize) {
    setPrevFilter(filter);
    setPrevSort(sort);
    setPrevPageSize(pageSize);
    setPage(1);
  }

  const paged = useMemo(
    () => paginate(visibleBanks, page, pageSize),
    [visibleBanks, page, pageSize],
  );

  const resetFilters = (): void => {
    setFilter(EMPTY_FILTER);
    setSort(DEFAULT_SORT);
  };

  // After an optimistic delete removes the active row, focus moves to the
  // next row (previous if it was the last) instead of falling back to <body>;
  // the always-mounted live region announces the selection change separately.
  const pendingFocusIdRef = useRef<string | null>(null);

  const handleBankDeleted = (id: string): void => {
    // Mirror the detail view's feedback: confirm the deletion with a toast.
    // The list stays put (no redirect), so without this the row simply
    // vanished with no acknowledgement.
    const deleted = banks.find((bank) => bank.id === id);
    toastNotifier.success("Bank deleted", deleted ? { description: deleted.name } : undefined);
    setDeleteError(null);
    const index = paged.rows.findIndex((bank) => bank.id === id);
    const neighbor = index === -1 ? undefined : (paged.rows[index + 1] ?? paged.rows[index - 1]);
    pendingFocusIdRef.current = neighbor && neighbor.id !== id ? neighbor.id : null;
    setBanks((prev) => prev.filter((bank) => bank.id !== id));
    setSelectedIds((prev) => {
      if (!prev.has(id)) return prev;
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  // A failed single delete: the dialog has already closed itself; anchor the
  // problem in the persistent surface above the list (one error per origin —
  // a newer failure replaces it).
  const handleBankDeleteFailed = useCallback((id: string, problem: ProblemDetails): void => {
    setDeleteError({ problem, bankId: id, scope: "single" });
  }, []);

  // Focus target once a bulk-error "Refresh list" settles: the bulk bar's
  // Delete when a selection survives, the list container otherwise.
  const pendingBulkFocusRef = useRef(false);
  const listContainerRef = useRef<HTMLDivElement>(null);

  // Announces list-level outcomes that change no selection count (e.g. a
  // stale-404 refresh). Always mounted, like the selection region.
  const [refreshAnnouncement, setRefreshAnnouncement] = useState("");

  // Typed recovery for the persistent delete error: a stale 404 heals with a
  // refresh; `bank-in-use` (and unmapped types) gets no action — recovery
  // lives outside the list.
  const handleDeleteErrorRefresh = async (): Promise<void> => {
    const current = deleteError;
    if (!current) return;
    if (current.scope === "single") {
      // The stale row disappears with the refresh; focus its neighbor.
      const index = paged.rows.findIndex((bank) => bank.id === current.bankId);
      const neighbor = index === -1 ? undefined : (paged.rows[index + 1] ?? paged.rows[index - 1]);
      pendingFocusIdRef.current = neighbor && neighbor.id !== current.bankId ? neighbor.id : null;
    } else {
      pendingBulkFocusRef.current = true;
    }
    setDeleteError(null);
    setRefreshAnnouncement("");
    await loadBanks();
    if (current.scope === "single") {
      // The bulk path announces through the selection region (its count
      // changes); a single-row refresh changes no count, so announce here.
      setRefreshAnnouncement("List refreshed");
    }
  };

  useEffect(() => {
    const id = pendingFocusIdRef.current;
    if (!id) return;
    pendingFocusIdRef.current = null;
    const escaped = globalThis.CSS.escape(id);
    const rows = globalThis.document.querySelectorAll<HTMLElement>(
      `[data-testid="banks-table__row-${escaped}"], [data-testid="banks-stacked__row-${escaped}"]`,
    );
    // Both responsive surfaces render the row; the breakpoint hides one with
    // display:none and focus() on a hidden node is a silent no-op — target the
    // visible one (offsetParent is null while hidden; jsdom falls back to the
    // first match).
    const row = [...rows].find((el) => el.offsetParent !== null) ?? rows[0];
    if (row) {
      row.focus();
    } else {
      // The precomputed neighbor can vanish with a refresh (re-pagination,
      // concurrent deletes) — never strand focus on <body>.
      listContainerRef.current?.focus();
    }
  }, [banks]);

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

  // Esc clears the selection — but only when no transient layer is open:
  // tooltips stop propagation when they consume Esc, and dialogs/menus live
  // in portals outside this subtree, so their Esc never reaches here.
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
      // The refresh itself failed — disarm so a later unrelated reload
      // doesn't steal focus out of nowhere.
      pendingBulkFocusRef.current = false;
      return;
    }
    if (state !== ViewStatus.READY) return;
    pendingBulkFocusRef.current = false;
    if (selectedIds.size > 0) {
      globalThis.document
        .querySelector<HTMLElement>(`[data-testid="${BULK_DELETE_TESTID}"]`)
        ?.focus();
    } else {
      listContainerRef.current?.focus();
    }
  }, [state, selectedIds, banks]);

  // Selection announcements: the polite region is ALWAYS mounted (a region
  // born with its first message is missed by screen readers) and rapid
  // changes — e.g. range selection — coalesce into the final count.
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

  const tableSelection = useMemo<DataTableSelection>(
    () => ({ mode: SelectionMode.MULTI, selected: selectedIds, onChange: setSelectedIds }),
    [selectedIds],
  );

  // Bulk delete: a pessimistic existence pre-check guards the optimistic
  // attempt — any stale id aborts before mutating anything; probe failures
  // other than 404 fail open to the attempt. After the attempt, 404
  // rejections do NOT resurrect rows (the bank is already gone) while other
  // failures restore the row AND its selection so the user retries without
  // re-picking. Failures land in the persistent error surface; the toast is
  // only a transient pointer.
  const bulkDeleteInFlightRef = useRef(false);
  const handleBulkDelete = async (): Promise<void> => {
    // The bulk bar stays mounted during the probe window — guard re-entry.
    if (bulkDeleteInFlightRef.current) return;
    const ids = [...selectedIds].filter((id) => banks.some((bank) => bank.id === id));
    if (ids.length === 0) return;
    bulkDeleteInFlightRef.current = true;
    try {
      await runBulkDelete(ids);
    } finally {
      bulkDeleteInFlightRef.current = false;
    }
  };

  const runBulkDelete = async (ids: string[]): Promise<void> => {
    const findBank = container.get<FindBank>("BackOfficeFindBank");
    const probes = await Promise.allSettled(ids.map((id) => findBank.run(id)));
    if (!mountedRef.current) return;
    const staleIndex = probes.findIndex(
      (probe) => probe.status === "rejected" && isNotFoundError(probe.reason),
    );
    if (staleIndex !== -1) {
      const stale = probes[staleIndex] as PromiseRejectedResult;
      setDeleteError({
        problem: (stale.reason as HttpError).problem,
        bankId: ids[staleIndex],
        scope: "bulk",
      });
      toastNotifier.error("Couldn't delete banks — see error details");
      return;
    }

    const snapshot = banks;
    const removing = new Set(ids);
    setBanks((prev) => prev.filter((bank) => !removing.has(bank.id)));
    setSelectedIds(new Set());

    const useCase = container.get<DeleteBank>("BackOfficeDeleteBank");
    const results = await Promise.allSettled(ids.map((id) => useCase.run(id)));
    if (!mountedRef.current) return;

    const rejections = ids.flatMap((id, index) => {
      const result = results[index];
      return result.status === "rejected" ? [{ id, reason: result.reason as unknown }] : [];
    });
    const succeeded = ids.length - rejections.length;
    if (succeeded > 0) {
      toastNotifier.success(`${succeeded} ${succeeded === 1 ? "bank" : "banks"} deleted`);
    }
    if (rejections.length === 0) {
      setDeleteError(null);
      return;
    }
    // A 404 rejection means the bank was already gone: don't resurrect it.
    const restorable = new Set(
      rejections.filter(({ reason }) => !isNotFoundError(reason)).map(({ id }) => id),
    );
    if (restorable.size > 0) {
      const restored = snapshot.filter((bank) => restorable.has(bank.id));
      setBanks((prev) => {
        const present = new Set(prev.map((bank) => bank.id));
        return [...prev, ...restored.filter((bank) => !present.has(bank.id))];
      });
      setSelectedIds((prev) => new Set([...prev, ...restorable]));
    }
    const first = rejections[0];
    const fallbackDetail = first.reason instanceof Error ? first.reason.message : "Unknown error";
    setDeleteError({
      problem:
        first.reason instanceof HttpError ? first.reason.problem : genericProblem(fallbackDetail),
      bankId: first.id,
      scope: "bulk",
    });
    toastNotifier.error("Some banks could not be deleted", {
      description: `${rejections.length} of ${ids.length} could not be deleted. See error details.`,
    });
  };

  // A realtime delta arriving while the list still shows a failed-load error
  // means that error is likely stale. Reconcile with a full silent reload rather
  // than trusting the delta: Mercure has no replay, so the events seen so far are
  // only a partial slice — promoting them to READY would render an incomplete list.
  // A reload already in flight wins, so a burst of deltas coalesces into a single
  // reconcile instead of launching one overlapping silent reload per event.
  const reconcileAfterErroredLoad = (): void => {
    if (state === ViewStatus.ERROR && !reloadingRef.current) {
      loadBanks({ silent: true });
    }
  };

  // Real-time sync of changes made by OTHER clients (Mercure). These reconcile
  // state silently (no toast): the acting user already got their own feedback,
  // and passive viewers shouldn't be spammed. Merges are id-keyed so duplicate
  // at-least-once deliveries are no-ops.
  useBankRealtime([bankTopics.collection], {
    onCreated: (incoming) => {
      setBanks((prev) => (prev.some((b) => b.id === incoming.id) ? prev : [incoming, ...prev]));
      reconcileAfterErroredLoad();
    },
    onUpdated: (incoming) => {
      setBanks((prev) => prev.map((b) => (b.id === incoming.id ? incoming : b)));
      reconcileAfterErroredLoad();
    },
    onDeleted: (deletedId) => {
      setBanks((prev) => prev.filter((b) => b.id !== deletedId));
      // Mirror the local-delete pruning so a remote delete of a selected row
      // doesn't leave a phantom id inflating the bulk-selection count.
      setSelectedIds((prev) => {
        if (!prev.has(deletedId)) return prev;
        const next = new Set(prev);
        next.delete(deletedId);
        return next;
      });
      reconcileAfterErroredLoad();
    },
    // On stream re-open after a drop, silently reconcile: events published during
    // the gap (Mercure has no replay) are otherwise lost and the list diverges.
    // Fire-and-forget: loadBanks handles its own errors.
    onReconnect: () => {
      loadBanks({ silent: true });
    },
  });

  // Typed recovery: only a stale `bank-not-found` heals from here. The
  // refresh recalculates the selection and re-derives the confirm phrase.
  const deleteRecoveryAction =
    deleteError?.problem.type === BankProblemType.NOT_FOUND ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          // Fire-and-forget: the handler resolves all errors internally.
          handleDeleteErrorRefresh();
        }}
        aria-label="Refresh list"
        title="Refresh the banks list"
        data-testid="banks-list__delete-error-refresh"
      >
        Refresh list
      </Button>
    ) : undefined;

  return (
    <div
      ref={listContainerRef}
      tabIndex={-1}
      className="banks-list mx-auto w-full max-w-[90rem] space-y-4 outline-none sm:space-y-6"
      data-testid="banks-list"
      data-state={boundaryState}
    >
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="banks-list__selection-status"
      >
        {selectionAnnouncement}
      </p>
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="banks-list__refresh-status"
      >
        {refreshAnnouncement}
      </p>
      <header
        className="banks-list__header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        data-testid="banks-list__header"
      >
        <div className="banks-list__heading min-w-0">
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="banks-list__title"
          >
            Banks
          </h1>
          <p className="text-muted-foreground mt-1 text-sm" data-testid="banks-list__subtitle">
            Manage the banks available in the back office.
          </p>
          {boundaryState === ViewStatus.READY ? (
            <p
              className="banks-list__total text-muted-foreground mt-1 text-xs"
              data-testid="banks-list__total"
            >
              Total banks: <span className="text-foreground font-medium">{banks.length}</span>
              {recentCount > 0 ? (
                <>
                  {" · "}
                  <span className="text-foreground font-medium">{recentCount}</span> added this week
                </>
              ) : null}
            </p>
          ) : null}
        </div>
        <Link
          href={bankRoutes.new}
          className={cn(buttonVariants({ size: "sm" }), "banks-list__new-button w-full sm:w-auto")}
          data-icon="inline-start"
          data-testid="banks-list__new-button"
          aria-label="Create a new bank"
          title="Create a new bank"
        >
          <Plus className="size-3.5" aria-hidden="true" />
          New bank
        </Link>
      </header>

      {boundaryState === ViewStatus.READY ? (
        <BanksFilters
          filter={filter}
          onFilterChange={setFilter}
          sort={sort}
          onSortChange={setSort}
          onReset={resetFilters}
          leading={
            visibleBanks.length > 0 ? (
              <div className="banks-list__display-toggles flex items-center gap-2">
                <BanksViewToggle view={view} onViewChange={setView} />
                <DensityToggle
                  density={density}
                  onDensityChange={setDensity}
                  testId="banks-list__density-toggle"
                />
              </div>
            ) : undefined
          }
        />
      ) : null}

      {deleteError ? (
        <MutationError
          problem={deleteError.problem}
          onDismiss={() => setDeleteError(null)}
          action={deleteRecoveryAction}
          testId="banks-list__delete-error"
        />
      ) : null}

      <AsyncBoundary
        state={boundaryState}
        data={banks}
        error={problem ?? undefined}
        loading={<BanksListSkeleton view={view} rows={Math.min(pageSize, 8)} />}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              loadBanks();
            }}
            title="Retry loading banks"
            aria-label="Retry loading banks"
            data-testid="banks-list__retry"
          >
            Retry
          </Button>
        }
        emptyVariant="first-run"
        emptyHeading="No banks yet"
        emptyDescription="Create the first bank to get started."
        emptyAction={
          <Link
            href={bankRoutes.new}
            className={cn(buttonVariants())}
            aria-label="Create your first bank"
            title="Create your first bank"
          >
            Create your first bank
          </Link>
        }
      >
        {() =>
          visibleBanks.length === 0 ? (
            <BanksEmptyFiltered onReset={resetFilters} />
          ) : (
            <>
              {view === "table" ? (
                <>
                  {/* Below md the table becomes stacked card-rows — zero
                      horizontal scroll on mobile; same data, same paging. */}
                  <BanksStackedList
                    banks={paged.rows}
                    onBankDeleted={handleBankDeleted}
                    onBankDeleteFailed={handleBankDeleteFailed}
                    selectedIds={selectedIds}
                    onToggleSelect={toggleSelect}
                    density={density}
                    className="md:hidden"
                  />
                  <div className="hidden md:block">
                    <BanksTable
                      banks={paged.rows}
                      sort={sort}
                      onSortChange={setSort}
                      onBankDeleted={handleBankDeleted}
                      onBankDeleteFailed={handleBankDeleteFailed}
                      selection={tableSelection}
                      density={density}
                    />
                  </div>
                </>
              ) : (
                <BanksCards
                  banks={paged.rows}
                  onBankDeleted={handleBankDeleted}
                  onBankDeleteFailed={handleBankDeleteFailed}
                  selectedIds={selectedIds}
                  onToggleSelect={toggleSelect}
                  density={density}
                />
              )}
              <BanksPagination
                page={paged.page}
                pageSize={pageSize}
                hasPrev={paged.hasPrev}
                hasNext={paged.hasNext}
                onPageChange={setPage}
                onPageSizeChange={setPageSize}
              />
              {nextCursor ? (
                <p className="text-muted-foreground text-xs" data-testid="banks-list__more-notice">
                  More banks available. Filters, sort, and pagination apply only to this page.
                </p>
              ) : null}
            </>
          )
        }
      </AsyncBoundary>

      {boundaryState === ViewStatus.READY && selectedIds.size > 0 ? (
        <BanksBulkBar
          count={selectedIds.size}
          names={banks.filter((bank) => selectedIds.has(bank.id)).map((bank) => bank.name)}
          onClear={clearSelection}
          onConfirmDelete={handleBulkDelete}
        />
      ) : null}
    </div>
  );
}
