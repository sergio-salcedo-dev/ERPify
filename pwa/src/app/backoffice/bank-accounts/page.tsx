"use client";

import { useMemo, type ReactNode } from "react";
import Link from "next/link";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";
import type { CreateBankAccountInput } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import { BankAccountProblemType } from "@/context/backoffice/bankaccount/domain/BankAccountProblemType";
import {
  bankAccountTopics,
  useBankAccountRealtime,
} from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";
import { useQueryState } from "@/context/shared/resource/application/createQueryState";
import { useResourceList } from "@/context/shared/resource/application/useResourceList";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import {
  AsyncBoundary,
  DensityToggle,
  LIST_DENSITY_STORAGE_KEY,
  MutationError,
  SelectionMode,
  isListDensity,
} from "@/components/erpify";
import type { DataTableSelection, DataTableSort, ListDensity } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { useStoredPreference } from "@/context/shared/storage/infrastructure/useStoredPreference";
import { bankAccountRoutes } from "./_lib/bankAccountRoutes";
import { BankAccountsTable } from "./_components/BankAccountsTable";
import { BankAccountsCards } from "./_components/BankAccountsCards";
import { BankAccountsStackedList } from "./_components/BankAccountsStackedList";
import { BankAccountsFilters } from "./_components/BankAccountsFilters";
import { BankAccountsPagination } from "../banks/[id]/accounts/_components/BankAccountsPagination";
import { BankAccountsColumnPicker } from "./_components/BankAccountsColumnPicker";
import {
  BankAccountsViewToggle,
  type BankAccountsView,
} from "./_components/BankAccountsViewToggle";
import { BankAccountsListSkeleton } from "./_components/BankAccountsListSkeleton";
import { BankAccountsEmptyFiltered } from "./_components/BankAccountsEmptyFiltered";
import { BULK_DELETE_TESTID, BankAccountsBulkBar } from "./_components/BankAccountsBulkBar";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  hasActiveFilter,
  isDefaultSort,
  type BankAccountsFilter,
  type BankAccountsSort,
} from "./_lib/bankAccountsFilterSort";
import { toBankAccountFilters, toBankAccountSort } from "./_lib/bankAccountsSearchCriteria";
import { BANK_ACCOUNTS_PAGE_SIZE_DEFAULT, isBankAccountsPageSize } from "./_lib/paginate";
import {
  BANK_ACCOUNTS_COLUMNS_STORAGE_KEY,
  DEFAULT_VISIBLE_COLUMNS,
  isStoredColumnsValue,
  parseColumns,
  serializeColumns,
  type BankAccountColumnKey,
} from "./_lib/bankAccountColumns";

const BANK_ACCOUNTS_VIEW_STORAGE_KEY = "erpify:bank-accounts-view";
const DEFAULT_VIEW: BankAccountsView = "table";

function isBankAccountsView(value: unknown): value is BankAccountsView {
  return value === "table" || value === "cards";
}

export default function BankAccountsListPage() {
  const query = useQueryState<BankAccountsFilter>({
    emptyFilter: EMPTY_FILTER,
    defaultSort: toBankAccountSort(DEFAULT_SORT),
    defaultPageSize: BANK_ACCOUNTS_PAGE_SIZE_DEFAULT,
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
    bulkDelete,
    listContainerRef,
    selectionAnnouncement,
    refreshAnnouncement,
  } = useResourceList<BankAccountCollectionRow, BankAccountsFilter, CreateBankAccountInput>({
    repositoryKey: "BackOfficeBankAccountCrudRepository",
    navigatorKey: "BackOfficeBankAccountResourceNavigator",
    query,
    toCriteria: (currentFilter, sort, limit) => ({
      filters: toBankAccountFilters(currentFilter),
      sort,
      limit,
    }),
    hasActiveFilter,
    getLabel: (account) => account.holderName,
    entitySingular: "account",
    entityPlural: "accounts",
    rowFocusTestIds: (id) => [`bank-accounts-list__row-${id}`, `bank-accounts-stacked__row-${id}`],
    bulkDeleteTestId: BULK_DELETE_TESTID,
  });

  // Hydration-safe persisted preferences: SSR and first client paint always
  // render the defaults; the stored values apply after hydration.
  const [view, setView] = useStoredPreference<BankAccountsView>(
    BANK_ACCOUNTS_VIEW_STORAGE_KEY,
    DEFAULT_VIEW,
    isBankAccountsView,
  );
  const [density, setDensity] = useStoredPreference<ListDensity>(
    LIST_DENSITY_STORAGE_KEY,
    "compact",
    isListDensity,
  );
  const [columnsRaw, setColumnsRaw] = useStoredPreference<string>(
    BANK_ACCOUNTS_COLUMNS_STORAGE_KEY,
    serializeColumns(DEFAULT_VISIBLE_COLUMNS),
    isStoredColumnsValue,
  );
  const columns = useMemo(() => parseColumns(columnsRaw), [columnsRaw]);
  const setColumns = (next: BankAccountColumnKey[]): void => setColumnsRaw(serializeColumns(next));

  // The table speaks `DataTableSort` ({columnId,direction}); the query holds the
  // backend-shaped `ResourceSort` ({field,direction}). Map at the boundary.
  const tableSort: BankAccountsSort = query.sort
    ? { columnId: query.sort.field, direction: query.sort.direction }
    : null;
  const setTableSort = (next: DataTableSort | null): void =>
    query.setSort(next ? { field: next.columnId, direction: next.direction } : null);

  const { filter } = query;

  // Real-time sync (Mercure) over the GLOBAL, cross-bank collection topic. Under
  // server-driven search the client cannot tell whether an incoming account
  // belongs on the current page (filter/sort/keyset live on the server), so every
  // event reconciles by silently refetching the current page — which also heals a
  // stale errored view. The broadcast payload is PII-free (no IBAN/holder), so the
  // list never renders from it; a `deleted` event also tombstones the id so the
  // bulk restore cannot resurrect a row removed during its re-probe window.
  useBankAccountRealtime([bankAccountTopics.collectionAll], {
    onCreated: () => {
      silentReload();
    },
    onUpdated: () => {
      silentReload();
    },
    onDeleted: (event) => {
      markDeleted(event.id);
      silentReload();
    },
    onReconnect: () => {
      silentReload();
    },
  });

  const tableSelection = useMemo<DataTableSelection>(
    () => ({ mode: SelectionMode.MULTI, selected: selectedIds, onChange: setSelectedIds }),
    [selectedIds, setSelectedIds],
  );

  // Keep the filters toolbar mounted whenever the user is actively filtering or
  // sorting, not only when READY: gating purely on READY would unmount the
  // toolbar mid-interaction and steal focus from the search box on every keystroke.
  const showFiltersToolbar =
    boundaryState === ViewStatus.READY || hasActiveFilter(filter) || !isDefaultSort(tableSort);

  // Typed recovery in the persistent error surface: a stale `bank-account-not-found`
  // heals in place via a refresh; a `bank-account-not-closed` deep-links to that
  // account's standalone edit form so the user can set it to CLOSED first. Other
  // types carry no action.
  const deleteRecoveryAction = ((): ReactNode => {
    if (!deleteError) return undefined;
    switch (deleteError.problem.type) {
      case BankAccountProblemType.NOT_FOUND:
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
            title="Refresh the accounts list"
            data-testid="bank-accounts-list__delete-error-refresh"
          >
            Refresh list
          </Button>
        );
      case BankAccountProblemType.NOT_CLOSED: {
        const account = items.find((candidate) => candidate.id === deleteError.itemId);
        if (!account) return undefined;
        return (
          <Link
            href={safeHref(bankAccountRoutes.edit(account.id))}
            className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
            aria-label="Edit account"
            title="Edit this account to close it"
            data-testid="bank-accounts-list__delete-error-edit"
          >
            Edit account
          </Link>
        );
      }
      default:
        return undefined;
    }
  })();

  return (
    <div
      ref={listContainerRef}
      tabIndex={-1}
      className="bank-accounts-list mx-auto w-full max-w-[90rem] space-y-4 outline-none sm:space-y-6"
      data-testid="bank-accounts-list"
      data-state={boundaryState}
    >
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="bank-accounts-list__selection-status"
      >
        {selectionAnnouncement}
      </p>
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="bank-accounts-list__refresh-status"
      >
        {refreshAnnouncement}
      </p>
      <header
        className="bank-accounts-list__header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        data-testid="bank-accounts-list__header"
      >
        <div className="bank-accounts-list__heading min-w-0">
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="bank-accounts-list__title"
          >
            Bank Accounts
          </h1>
          <p
            className="text-muted-foreground mt-1 text-sm"
            data-testid="bank-accounts-list__subtitle"
          >
            Every bank account across all banks in the back office.
          </p>
        </div>
      </header>

      {showFiltersToolbar ? (
        <BankAccountsFilters
          filter={filter}
          onFilterChange={query.setFilter}
          sort={tableSort}
          onSortChange={setTableSort}
          onReset={query.reset}
          leading={
            items.length > 0 ? (
              <div className="bank-accounts-list__display-toggles flex items-center gap-2">
                <BankAccountsViewToggle view={view} onViewChange={setView} />
                <DensityToggle
                  density={density}
                  onDensityChange={setDensity}
                  testId="bank-accounts-list__density-toggle"
                />
                {/* Column visibility is a table-only affordance — the cards render a
                    fixed layout, so offering the picker there would be a no-op. */}
                {view === "table" ? (
                  <BankAccountsColumnPicker
                    visible={columns}
                    onChange={setColumns}
                    testId="bank-accounts-list__columns"
                  />
                ) : null}
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
          testId="bank-accounts-list__delete-error"
        />
      ) : null}

      <AsyncBoundary
        state={boundaryState}
        data={items}
        error={problem ?? undefined}
        loading={<BankAccountsListSkeleton view={view} rows={Math.min(query.pageSize, 8)} />}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              reload();
            }}
            title="Retry loading accounts"
            aria-label="Retry loading accounts"
            data-testid="bank-accounts-list__retry"
          >
            Retry
          </Button>
        }
        emptyVariant="first-run"
        emptyHeading="No bank accounts yet"
        emptyDescription="Bank accounts created under any bank will appear here."
      >
        {() =>
          items.length === 0 ? (
            <BankAccountsEmptyFiltered onReset={query.reset} />
          ) : (
            <>
              {view === "table" ? (
                <>
                  {/* Below md the table becomes stacked card-rows — zero
                      horizontal scroll on mobile; same data, same paging. */}
                  <BankAccountsStackedList
                    accounts={items}
                    onAccountDeleted={deleteItem}
                    onAccountDeleteFailed={deleteFailed}
                    selectedIds={selectedIds}
                    onToggleSelect={toggleSelect}
                    onSelectionChange={setSelectedIds}
                    density={density}
                    className="md:hidden"
                  />
                  <div className="hidden md:block">
                    <BankAccountsTable
                      accounts={items}
                      visible={columns}
                      sort={tableSort}
                      onSortChange={setTableSort}
                      onAccountDeleted={deleteItem}
                      onAccountDeleteFailed={deleteFailed}
                      selection={tableSelection}
                      density={density}
                    />
                  </div>
                </>
              ) : (
                <BankAccountsCards
                  accounts={items}
                  onAccountDeleted={deleteItem}
                  onAccountDeleteFailed={deleteFailed}
                  selectedIds={selectedIds}
                  onToggleSelect={toggleSelect}
                  density={density}
                />
              )}
              <BankAccountsPagination
                pageSize={
                  isBankAccountsPageSize(query.pageSize)
                    ? query.pageSize
                    : BANK_ACCOUNTS_PAGE_SIZE_DEFAULT
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
        <BankAccountsBulkBar
          count={selectedIds.size}
          names={items
            .filter((account) => selectedIds.has(account.id))
            .map((account) => account.holderName)}
          onClear={clearSelection}
          onConfirmDelete={bulkDelete}
        />
      ) : null}
    </div>
  );
}
