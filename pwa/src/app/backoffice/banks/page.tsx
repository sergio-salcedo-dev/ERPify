"use client";

import { useMemo, type ReactNode } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { BankInput } from "@/context/backoffice/bank/domain/BankRepository";
import { BankProblemType } from "@/context/backoffice/bank/domain/BankProblemType";
import { useQueryState } from "@/context/shared/resource/application/createQueryState";
import { useResourceList } from "@/context/shared/resource/application/useResourceList";
import { useBanksCount } from "@/context/backoffice/bank/application/useBanksCount";
import { ViewStatus } from "@/context/shared/domain/types/status";
import {
  AsyncBoundary,
  DensityToggle,
  LIST_DENSITY_STORAGE_KEY,
  MutationError,
  RecordSheet,
  SelectionMode,
  isListDensity,
} from "@/components/erpify";
import type { DataTableSelection, DataTableSort, ListDensity } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { useStoredPreference } from "@/lib/useStoredPreference";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { bankTopics, useBankRealtime } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import { BanksTable } from "./_components/BanksTable";
import { BanksColumnPicker } from "./_components/BanksColumnPicker";
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
  hasActiveFilter,
  isDefaultSort,
  type BanksFilter,
  type BanksSort,
} from "./_lib/banksFilterSort";
import { toBankFilters, toBankSort } from "./_lib/banksSearchCriteria";
import { BANKS_PAGE_SIZE_DEFAULT, isBanksPageSize } from "./_lib/paginate";
import { bankRoutes } from "./_lib/bankRoutes";
import {
  BANKS_COLUMNS_STORAGE_KEY,
  DEFAULT_VISIBLE_COLUMNS,
  isStoredColumnsValue,
  parseColumns,
  serializeColumns,
  type BankColumnKey,
} from "./_lib/bankColumns";

const BANKS_VIEW_STORAGE_KEY = "erpify:banks-view";
const DEFAULT_VIEW: BanksView = "table";

function isBanksView(value: unknown): value is BanksView {
  return value === "table" || value === "cards";
}

export default function BanksListPage() {
  const query = useQueryState<BanksFilter>({
    emptyFilter: EMPTY_FILTER,
    defaultSort: toBankSort(DEFAULT_SORT),
    defaultPageSize: BANKS_PAGE_SIZE_DEFAULT,
  });

  const {
    state: boundaryState,
    items,
    paginationActions,
    problem,
    selectedIds,
    setSelectedIds,
    toggleSelect,
    clearSelection,
    markDeleted,
    reload,
    silentReload,
    deleteItem,
    deleteFailed,
    deleteError,
    setDeleteError,
    handleDeleteErrorRefresh,
    peekId,
    setPeekId,
    bulkDelete,
    listContainerRef,
    selectionAnnouncement,
    refreshAnnouncement,
  } = useResourceList<Bank, BanksFilter, BankInput>({
    repositoryKey: "BackOfficeBankCrudRepository",
    navigatorKey: "BackOfficeBankResourceNavigator",
    query,
    toCriteria: (currentFilter, sort, limit) => ({
      filters: toBankFilters(currentFilter),
      sort,
      limit,
    }),
    hasActiveFilter,
    getLabel: (bank) => bank.name,
    entitySingular: "bank",
    entityPlural: "banks",
    rowFocusTestIds: (id) => [`banks-table__row-${id}`, `banks-stacked__row-${id}`],
    bulkDeleteTestId: BULK_DELETE_TESTID,
  });

  // Total banks from the projection read model (not the page envelope `count`,
  // which is null under LIGHT pagination). Auxiliary: a failed fetch never
  // crashes the list, and the realtime callbacks refresh it on create/delete.
  const { count: totalBanks, refresh: refreshBankCount } = useBanksCount();

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
  // Column visibility persists as a CSV string (useStoredPreference is string-only).
  const [columnsRaw, setColumnsRaw] = useStoredPreference<string>(
    BANKS_COLUMNS_STORAGE_KEY,
    serializeColumns(DEFAULT_VISIBLE_COLUMNS),
    isStoredColumnsValue,
  );
  const columns = useMemo(() => parseColumns(columnsRaw), [columnsRaw]);
  const setColumns = (next: BankColumnKey[]): void => setColumnsRaw(serializeColumns(next));

  // The table speaks `DataTableSort` ({columnId,direction}); the query holds the
  // backend-shaped `ResourceSort` ({field,direction}). Map at the boundary.
  const tableSort: BanksSort = query.sort
    ? { columnId: query.sort.field, direction: query.sort.direction }
    : null;
  const setTableSort = (next: DataTableSort | null): void =>
    query.setSort(next ? { field: next.columnId, direction: next.direction } : null);

  const { filter } = query;

  // Real-time sync (Mercure). Under server-driven search the client cannot tell
  // whether an incoming bank belongs on the current page (the filter/sort/keyset
  // all live on the server), so every event reconciles by silently refetching the
  // current page — which also heals a stale errored view. A `deleted` event also
  // tombstones the id so the bulk restore cannot resurrect a row removed during
  // its re-probe window. Silent: the acting user already has their own feedback
  // and passive viewers are not spammed.
  useBankRealtime([bankTopics.collection], {
    onCreated: () => {
      silentReload();
      refreshBankCount();
    },
    onUpdated: () => {
      silentReload();
    },
    onDeleted: (deletedId) => {
      markDeleted(deletedId);
      silentReload();
      refreshBankCount();
    },
    onReconnect: () => {
      silentReload();
      refreshBankCount();
    },
  });

  const tableSelection = useMemo<DataTableSelection>(
    () => ({ mode: SelectionMode.MULTI, selected: selectedIds, onChange: setSelectedIds }),
    [selectedIds, setSelectedIds],
  );

  const peekBank = useMemo(() => items.find((bank) => bank.id === peekId) ?? null, [items, peekId]);

  // Keep the filters toolbar mounted whenever the user is actively filtering or
  // sorting, not only when READY: gating purely on READY would unmount the
  // toolbar mid-interaction, collapsing the panel and stealing focus from the
  // search box on every keystroke.
  const showFiltersToolbar =
    boundaryState === ViewStatus.READY || hasActiveFilter(filter) || !isDefaultSort(tableSort);

  // Typed recovery in the persistent error surface, keyed off the problem type:
  // a stale `bank-not-found` heals in place via a refresh; a single `bank-in-use`
  // deep-links to that bank's accounts surface, while a bulk in-use rejection
  // restores each flagged row (its own optimistic guard) and points the user
  // there. Other types carry no action.
  const deleteRecoveryAction = ((): ReactNode => {
    switch (deleteError?.problem.type) {
      case BankProblemType.NOT_FOUND:
        return (
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
        );
      case BankProblemType.IN_USE:
        return deleteError.scope === "single" ? (
          <Link
            href={safeHref(bankRoutes.accounts(deleteError.itemId))}
            className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
            aria-label="View associated accounts"
            title="View the accounts associated with this bank"
            data-testid="banks-list__delete-error-view-accounts"
          >
            View accounts
          </Link>
        ) : (
          <span
            className="text-muted-foreground text-sm"
            data-testid="banks-list__delete-error-in-use-hint"
          >
            Open each flagged bank below to view its accounts.
          </span>
        );
      default:
        return undefined;
    }
  })();

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
          <p
            className="banks-list__count text-muted-foreground mt-1 text-xs"
            data-testid="banks-list__count"
          >
            {totalBanks === 1 ? "1 bank total" : `${totalBanks} banks total`}
          </p>
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

      {showFiltersToolbar ? (
        <BanksFilters
          filter={filter}
          onFilterChange={query.setFilter}
          sort={tableSort}
          onSortChange={setTableSort}
          onReset={query.reset}
          leading={
            items.length > 0 ? (
              <div className="banks-list__display-toggles flex items-center gap-2">
                <BanksViewToggle view={view} onViewChange={setView} />
                <DensityToggle
                  density={density}
                  onDensityChange={setDensity}
                  testId="banks-list__density-toggle"
                />
                <BanksColumnPicker
                  visible={columns}
                  onChange={setColumns}
                  testId="banks-list__columns"
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
        data={items}
        error={problem ?? undefined}
        loading={<BanksListSkeleton view={view} rows={Math.min(query.pageSize, 8)} />}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              reload();
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
          items.length === 0 ? (
            <BanksEmptyFiltered onReset={query.reset} />
          ) : (
            <>
              {view === "table" ? (
                <>
                  {/* Below md the table becomes stacked card-rows — zero
                      horizontal scroll on mobile; same data, same paging. */}
                  <BanksStackedList
                    banks={items}
                    onBankDeleted={deleteItem}
                    onBankDeleteFailed={deleteFailed}
                    selectedIds={selectedIds}
                    onToggleSelect={toggleSelect}
                    onSelectionChange={setSelectedIds}
                    onBankPeek={setPeekId}
                    density={density}
                    className="md:hidden"
                  />
                  <div className="hidden md:block">
                    <BanksTable
                      banks={items}
                      visible={columns}
                      sort={tableSort}
                      onSortChange={setTableSort}
                      onBankDeleted={deleteItem}
                      onBankDeleteFailed={deleteFailed}
                      selection={tableSelection}
                      onBankPeek={setPeekId}
                      density={density}
                    />
                  </div>
                </>
              ) : (
                <BanksCards
                  banks={items}
                  onBankDeleted={deleteItem}
                  onBankDeleteFailed={deleteFailed}
                  selectedIds={selectedIds}
                  onToggleSelect={toggleSelect}
                  density={density}
                />
              )}
              <BanksPagination
                pageSize={
                  isBanksPageSize(query.pageSize) ? query.pageSize : BANKS_PAGE_SIZE_DEFAULT
                }
                hasPrev={paginationActions.hasPrev}
                hasNext={paginationActions.hasNext}
                onPrev={paginationActions.goPrev}
                onNext={paginationActions.goNext}
                onPageSizeChange={query.setPageSize}
              />
            </>
          )
        }
      </AsyncBoundary>

      {boundaryState === ViewStatus.READY && selectedIds.size > 0 ? (
        <BanksBulkBar
          count={selectedIds.size}
          names={items.filter((bank) => selectedIds.has(bank.id)).map((bank) => bank.name)}
          onClear={clearSelection}
          onConfirmDelete={bulkDelete}
        />
      ) : null}

      {peekBank ? (
        <RecordSheet
          open
          onOpenChange={(open) => {
            if (!open) setPeekId(null);
          }}
          title={peekBank.name}
          subtitle={peekBank.shortName}
          testId="banks-list__peek"
          footer={
            <Link
              href={safeHref(bankRoutes.detail(peekBank.id))}
              className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
              aria-label="Open detail"
              title={`Open ${peekBank.name}`}
              data-testid="banks-peek__detail-link"
            >
              Open detail
            </Link>
          }
        >
          <dl className="banks-peek space-y-3 text-sm">
            <div className="banks-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Id</dt>
              <dd className="mt-0.5 font-mono text-xs break-all" data-testid="banks-peek__id">
                {peekBank.id}
              </dd>
            </div>
            <div className="banks-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Name</dt>
              <dd className="mt-0.5" data-testid="banks-peek__name">
                {peekBank.name}
              </dd>
            </div>
            <div className="banks-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Code</dt>
              <dd className="mt-0.5 font-mono uppercase" data-testid="banks-peek__shortname">
                {peekBank.shortName}
              </dd>
            </div>
            <div className="banks-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Created</dt>
              <dd className="mt-0.5" data-testid="banks-peek__created-at">
                {dateTimeProvider.formatIsoToLocalDateTime(peekBank.createdAt)}
              </dd>
            </div>
            <div className="banks-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Updated</dt>
              <dd className="mt-0.5" data-testid="banks-peek__updated-at">
                {dateTimeProvider.formatIsoToLocalDateTime(peekBank.updatedAt)}
              </dd>
            </div>
          </dl>
        </RecordSheet>
      ) : null}
    </div>
  );
}
