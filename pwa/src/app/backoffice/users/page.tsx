"use client";

import { useMemo } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import type { User } from "@/context/backoffice/user/domain/User";
import type { UserInput } from "@/context/backoffice/user/domain/UserRepository";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { useQueryState } from "@/context/shared/resource/application/createQueryState";
import { useResourceList } from "@/context/shared/resource/application/useResourceList";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
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
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { useStoredPreference } from "@/context/shared/storage/infrastructure/useStoredPreference";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UsersTable } from "./_components/UsersTable";
import { UsersCards } from "./_components/UsersCards";
import { UsersStackedList } from "./_components/UsersStackedList";
import { UsersFilters } from "./_components/UsersFilters";
import { UsersPagination } from "./_components/UsersPagination";
import { UsersViewToggle, type UsersView } from "./_components/UsersViewToggle";
import { UsersColumnPicker } from "./_components/UsersColumnPicker";
import { UsersListSkeleton } from "./_components/UsersListSkeleton";
import { UsersEmptyFiltered } from "./_components/UsersEmptyFiltered";
import { BULK_DELETE_TESTID, UsersBulkBar } from "./_components/UsersBulkBar";
import { UserStatusBadge } from "./_components/UserStatusBadge";
import { RolesBadges } from "./_components/RolesBadges";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  hasActiveFilter,
  isDefaultSort,
  type UsersFilter,
  type UsersSort,
} from "./_lib/usersFilterSort";
import { toUserFilters, toUserSort } from "./_lib/usersSearchCriteria";
import { USERS_PAGE_SIZE_DEFAULT, isUsersPageSize } from "./_lib/paginate";
import {
  DEFAULT_VISIBLE_COLUMNS,
  USERS_COLUMNS_STORAGE_KEY,
  isStoredColumnsValue,
  parseColumns,
  serializeColumns,
  type UserColumnKey,
} from "./_lib/userColumns";
import { userRoutes } from "./_lib/userRoutes";

const USERS_VIEW_STORAGE_KEY = "erpify:users-view";
const DEFAULT_VIEW: UsersView = "table";

function isUsersView(value: unknown): value is UsersView {
  return value === "table" || value === "cards";
}

export default function UsersListPage() {
  const query = useQueryState<UsersFilter>({
    emptyFilter: EMPTY_FILTER,
    defaultSort: toUserSort(DEFAULT_SORT),
    defaultPageSize: USERS_PAGE_SIZE_DEFAULT,
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
    reload,
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
  } = useResourceList<User, UsersFilter, UserInput>({
    repositoryKey: "BackOfficeUserRepository",
    navigatorKey: "BackOfficeUserSearchNavigator",
    query,
    toCriteria: (currentFilter, sort, limit) => ({
      filters: toUserFilters(currentFilter),
      sort,
      limit,
    }),
    hasActiveFilter,
    getLabel: (user) => user.email,
    entitySingular: "user",
    entityPlural: "users",
    rowFocusTestIds: (id) => [`users-table__row-${id}`, `users-stacked__row-${id}`],
    bulkDeleteTestId: BULK_DELETE_TESTID,
  });

  const [view, setView] = useStoredPreference<UsersView>(
    USERS_VIEW_STORAGE_KEY,
    DEFAULT_VIEW,
    isUsersView,
  );
  const [density, setDensity] = useStoredPreference<ListDensity>(
    LIST_DENSITY_STORAGE_KEY,
    "compact",
    isListDensity,
  );
  // Column visibility persists as a CSV string (useStoredPreference is string-only).
  const [columnsRaw, setColumnsRaw] = useStoredPreference<string>(
    USERS_COLUMNS_STORAGE_KEY,
    serializeColumns(DEFAULT_VISIBLE_COLUMNS),
    isStoredColumnsValue,
  );
  const columns = useMemo(() => parseColumns(columnsRaw), [columnsRaw]);
  const setColumns = (next: UserColumnKey[]): void => setColumnsRaw(serializeColumns(next));

  // The table speaks `DataTableSort` ({columnId,direction}); the query holds the
  // backend-shaped `ResourceSort` ({field,direction}). Map at the boundary.
  const tableSort: UsersSort = query.sort
    ? { columnId: query.sort.field, direction: query.sort.direction }
    : null;
  const setTableSort = (next: DataTableSort | null): void =>
    query.setSort(next ? { field: next.columnId, direction: next.direction } : null);

  const { filter } = query;

  const tableSelection = useMemo<DataTableSelection>(
    () => ({
      mode: SelectionMode.MULTI,
      selected: selectedIds,
      onChange: setSelectedIds,
    }),
    [selectedIds, setSelectedIds],
  );

  const peekUser = useMemo(() => items.find((user) => user.id === peekId) ?? null, [items, peekId]);

  const showFiltersToolbar =
    boundaryState === ViewStatus.READY || hasActiveFilter(filter) || !isDefaultSort(tableSort);

  const deleteRecoveryAction =
    deleteError?.problem.type === UserProblemType.NOT_FOUND ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          // Fire-and-forget: the handler resolves all errors internally.
          handleDeleteErrorRefresh();
        }}
        aria-label="Refresh list"
        title="Refresh the users list"
        data-testid="users-list__delete-error-refresh"
      >
        Refresh list
      </Button>
    ) : undefined;

  return (
    <div
      ref={listContainerRef}
      tabIndex={-1}
      className="users-list mx-auto w-full max-w-[90rem] space-y-4 outline-none sm:space-y-6"
      data-testid="users-list"
      data-state={boundaryState}
    >
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="users-list__selection-status"
      >
        {selectionAnnouncement}
      </p>
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="users-list__refresh-status"
      >
        {refreshAnnouncement}
      </p>

      <header
        className="users-list__header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        data-testid="users-list__header"
      >
        <div className="users-list__heading min-w-0">
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="users-list__title"
          >
            Users
          </h1>
          <p className="text-muted-foreground mt-1 text-sm" data-testid="users-list__subtitle">
            Manage the users and their access in the back office.
          </p>
        </div>
        <Can permission={Permission.USERS_WRITE}>
          <Link
            href={userRoutes.new}
            className={cn(
              buttonVariants({ size: "sm" }),
              "users-list__new-button w-full sm:w-auto",
            )}
            data-icon="inline-start"
            data-testid="users-list__new-button"
            aria-label="Create a new user"
            title="Create a new user"
          >
            <Plus className="size-3.5" aria-hidden="true" />
            New user
          </Link>
        </Can>
      </header>

      {showFiltersToolbar ? (
        <UsersFilters
          filter={filter}
          onFilterChange={query.setFilter}
          sort={tableSort}
          onSortChange={setTableSort}
          onReset={query.reset}
          leading={
            items.length > 0 ? (
              <div className="users-list__display-toggles flex items-center gap-2">
                <UsersViewToggle view={view} onViewChange={setView} />
                <DensityToggle
                  density={density}
                  onDensityChange={setDensity}
                  testId="users-list__density-toggle"
                />
                <UsersColumnPicker
                  visible={columns}
                  onChange={setColumns}
                  testId="users-list__columns"
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
          testId="users-list__delete-error"
        />
      ) : null}

      <AsyncBoundary
        state={boundaryState}
        data={items}
        error={problem ?? undefined}
        loading={<UsersListSkeleton view={view} rows={Math.min(query.pageSize, 8)} />}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              reload();
            }}
            title="Retry loading users"
            aria-label="Retry loading users"
            data-testid="users-list__retry"
          >
            Retry
          </Button>
        }
        emptyVariant="first-run"
        emptyHeading="No users yet"
        emptyDescription="Create the first user to get started."
        emptyAction={
          <Can permission={Permission.USERS_WRITE}>
            <Link
              href={userRoutes.new}
              className={cn(buttonVariants())}
              aria-label="Create your first user"
              title="Create your first user"
            >
              Create your first user
            </Link>
          </Can>
        }
      >
        {() =>
          items.length === 0 ? (
            <UsersEmptyFiltered onReset={query.reset} />
          ) : (
            <>
              {view === "table" ? (
                <>
                  <UsersStackedList
                    users={items}
                    onUserDeleted={deleteItem}
                    onUserDeleteFailed={deleteFailed}
                    selectedIds={selectedIds}
                    onToggleSelect={toggleSelect}
                    onSelectionChange={setSelectedIds}
                    onUserPeek={setPeekId}
                    density={density}
                    className="md:hidden"
                  />
                  <div className="hidden md:block">
                    <UsersTable
                      users={items}
                      visible={columns}
                      sort={tableSort}
                      onSortChange={setTableSort}
                      onUserDeleted={deleteItem}
                      onUserDeleteFailed={deleteFailed}
                      selection={tableSelection}
                      onUserPeek={setPeekId}
                      density={density}
                    />
                  </div>
                </>
              ) : (
                <UsersCards
                  users={items}
                  onUserDeleted={deleteItem}
                  onUserDeleteFailed={deleteFailed}
                  selectedIds={selectedIds}
                  onToggleSelect={toggleSelect}
                  density={density}
                />
              )}
              <UsersPagination
                pageSize={
                  isUsersPageSize(query.pageSize) ? query.pageSize : USERS_PAGE_SIZE_DEFAULT
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
        <UsersBulkBar
          count={selectedIds.size}
          names={items.filter((user) => selectedIds.has(user.id)).map((user) => user.email)}
          onClear={clearSelection}
          onConfirmDelete={bulkDelete}
        />
      ) : null}

      {peekUser ? (
        <RecordSheet
          open
          onOpenChange={(open) => {
            if (!open) setPeekId(null);
          }}
          title={peekUser.email}
          testId="users-list__peek"
          footer={
            <Link
              href={safeHref(userRoutes.detail(peekUser.id))}
              className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
              aria-label="Open detail"
              title={`Open ${peekUser.email}`}
              data-testid="users-peek__detail-link"
            >
              Open detail
            </Link>
          }
        >
          <dl className="users-peek space-y-3 text-sm">
            <div className="users-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Id</dt>
              <dd className="mt-0.5 font-mono text-xs break-all" data-testid="users-peek__id">
                {peekUser.id}
              </dd>
            </div>
            <div className="users-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Email</dt>
              <dd className="mt-0.5" data-testid="users-peek__email">
                {peekUser.email}
              </dd>
            </div>
            <div className="users-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Roles</dt>
              <dd className="mt-0.5">
                <RolesBadges roles={peekUser.roles} testId="users-peek__roles" />
              </dd>
            </div>
            <div className="users-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Status</dt>
              <dd className="mt-0.5">
                <UserStatusBadge status={peekUser.status} testId="users-peek__status" />
              </dd>
            </div>
            <div className="users-peek__field">
              <dt className="text-muted-foreground text-xs font-medium uppercase">Created</dt>
              <dd className="mt-0.5" data-testid="users-peek__created-at">
                {dateTimeProvider.formatIsoToLocalDateTime(peekUser.createdAt)}
              </dd>
            </div>
          </dl>
        </RecordSheet>
      ) : null}
    </div>
  );
}
