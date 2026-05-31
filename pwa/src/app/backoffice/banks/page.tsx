"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBanks } from "@/context/backoffice/bank/application/SearchBanks";
import { DeleteBank } from "@/context/backoffice/bank/application/DeleteBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { AsyncBoundary, SelectionMode } from "@/components/erpify";
import type { DataTableSelection } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { uuidV7 } from "@/lib/uuidV7";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { BanksTable } from "./_components/BanksTable";
import { BanksCards } from "./_components/BanksCards";
import { BanksFilters } from "./_components/BanksFilters";
import { BanksPagination } from "./_components/BanksPagination";
import { BanksViewToggle, type BanksView } from "./_components/BanksViewToggle";
import { BanksListSkeleton } from "./_components/BanksListSkeleton";
import { BanksEmptyFiltered } from "./_components/BanksEmptyFiltered";
import { BanksBulkBar } from "./_components/BanksBulkBar";
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
  const [view, setView] = useState<BanksView>(() => {
    if (globalThis.window === undefined) return DEFAULT_VIEW;
    const stored = globalThis.localStorage.getItem(BANKS_VIEW_STORAGE_KEY);
    return isBanksView(stored) ? stored : DEFAULT_VIEW;
  });

  useEffect(() => {
    if (globalThis.window === undefined) return;
    globalThis.localStorage.setItem(BANKS_VIEW_STORAGE_KEY, view);
  }, [view]);

  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const loadBanks = useCallback(async () => {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
      const result = await useCase.run();
      if (!mountedRef.current) return;
      setBanks(result.banks);
      setNextCursor(result.nextCursor);
      setState(result.banks.length === 0 ? ViewStatus.EMPTY : ViewStatus.READY);
    } catch (err) {
      if (!mountedRef.current) return;
      const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
      const nextProblem = err instanceof HttpError ? err.problem : genericProblem(fallbackDetail);
      setProblem(nextProblem);
      setState(ViewStatus.ERROR);
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

  const handleBankDeleted = (id: string): void => {
    // Mirror the detail view's feedback: confirm the deletion with a toast.
    // The list stays put (no redirect), so without this the row simply
    // vanished with no acknowledgement.
    const deleted = banks.find((bank) => bank.id === id);
    toastNotifier.success("Bank deleted", deleted ? { description: deleted.name } : undefined);
    setBanks((prev) => prev.filter((bank) => bank.id !== id));
    setSelectedIds((prev) => {
      if (!prev.has(id)) return prev;
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

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

  const tableSelection = useMemo<DataTableSelection>(
    () => ({ mode: SelectionMode.MULTI, selected: selectedIds, onChange: setSelectedIds }),
    [selectedIds],
  );

  // Bulk delete is OPTIMISTIC (a documented exception to the pessimistic-by-
  // default rule): the selected rows vanish immediately, then any failures are
  // restored and reported. Single-row delete stays pessimistic (its dialog
  // keeps the error inline).
  const handleBulkDelete = async (): Promise<void> => {
    const ids = [...selectedIds].filter((id) => banks.some((bank) => bank.id === id));
    if (ids.length === 0) return;
    const snapshot = banks;
    const removing = new Set(ids);
    setBanks((prev) => prev.filter((bank) => !removing.has(bank.id)));
    setSelectedIds(new Set());

    const useCase = container.get<DeleteBank>("BackOfficeDeleteBank");
    const results = await Promise.allSettled(ids.map((id) => useCase.run(id)));
    if (!mountedRef.current) return;

    const failed = ids.filter((_, index) => results[index].status === "rejected");
    const succeeded = ids.length - failed.length;
    if (succeeded > 0) {
      toastNotifier.success(`${succeeded} ${succeeded === 1 ? "bank" : "banks"} deleted`);
    }
    if (failed.length > 0) {
      const failedSet = new Set(failed);
      const restored = snapshot.filter((bank) => failedSet.has(bank.id));
      setBanks((prev) => {
        const present = new Set(prev.map((bank) => bank.id));
        return [...prev, ...restored.filter((bank) => !present.has(bank.id))];
      });
      toastNotifier.error("Some banks could not be deleted", {
        description: `${failed.length} of ${ids.length} could not be deleted.`,
      });
    }
  };

  // Real-time sync of changes made by OTHER clients (Mercure). These reconcile
  // state silently (no toast): the acting user already got their own feedback,
  // and passive viewers shouldn't be spammed. Merges are id-keyed so duplicate
  // at-least-once deliveries are no-ops.
  useBankRealtime([bankTopics.collection], {
    onCreated: (incoming) => {
      setBanks((prev) => (prev.some((b) => b.id === incoming.id) ? prev : [incoming, ...prev]));
      setState((prev) => (prev === ViewStatus.EMPTY ? ViewStatus.READY : prev));
    },
    onUpdated: (incoming) => {
      setBanks((prev) => prev.map((b) => (b.id === incoming.id ? incoming : b)));
    },
    onDeleted: (deletedId) => {
      setBanks((prev) => prev.filter((b) => b.id !== deletedId));
    },
  });

  return (
    <div
      className="banks-list mx-auto w-full max-w-screen-2xl space-y-4 sm:space-y-6 2xl:max-w-[120rem]"
      data-testid="banks-list"
      data-state={state}
    >
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
          {state === ViewStatus.READY ? (
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

      {state === ViewStatus.READY ? (
        <BanksFilters
          filter={filter}
          onFilterChange={setFilter}
          sort={sort}
          onSortChange={setSort}
          onReset={resetFilters}
          leading={
            visibleBanks.length > 0 ? (
              <BanksViewToggle view={view} onViewChange={setView} />
            ) : undefined
          }
        />
      ) : null}

      {state === ViewStatus.READY && selectedIds.size > 0 ? (
        <BanksBulkBar
          count={selectedIds.size}
          onClear={clearSelection}
          onConfirmDelete={handleBulkDelete}
        />
      ) : null}

      <AsyncBoundary
        state={state}
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
                <BanksTable
                  banks={paged.rows}
                  sort={sort}
                  onSortChange={setSort}
                  onBankDeleted={handleBankDeleted}
                  selection={tableSelection}
                />
              ) : (
                <BanksCards
                  banks={paged.rows}
                  onBankDeleted={handleBankDeleted}
                  selectedIds={selectedIds}
                  onToggleSelect={toggleSelect}
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
    </div>
  );
}
