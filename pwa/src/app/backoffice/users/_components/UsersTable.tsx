"use client";

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import type { User } from "@/context/backoffice/user/domain/User";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { DataTable, TruncatedText } from "@/components/erpify";
import type { DataTableColumn, DataTableSelection, DataTableSort } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/DateTimeProvider/infrastructure";
import { safeHref } from "@/lib/safeHref";
import { userRoutes } from "../_lib/userRoutes";
import type { UserColumnKey } from "../_lib/userColumns";
import { UserStatusBadge } from "./UserStatusBadge";
import { RolesBadges } from "./RolesBadges";
import { UserRowActions } from "./UserRowActions";

interface UsersTableProps {
  users: User[];
  /** Which columns are visible (email always rendered; the rest are toggleable). */
  visible: UserColumnKey[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  onUserDeleted?: (id: string) => void;
  onUserDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selection?: DataTableSelection;
  onUserPeek?: (id: string) => void;
  density?: "compact" | "comfortable";
}

const renderEmailCell = (row: User) => <TruncatedText value={row.email} />;

const renderRolesCell = (row: User) => (
  <RolesBadges roles={row.roles} testId={`users-table__roles-${row.id}`} />
);

const renderStatusCell = (row: User) => (
  <UserStatusBadge status={row.status} testId={`users-table__status-${row.id}`} />
);

const renderRelativeCell = (iso: string, testId: string) => (
  <span
    className="tabular-nums"
    title={dateTimeProvider.formatIsoToLocalDateTime(iso)}
    data-testid={testId}
  >
    {dateTimeProvider.formatIsoToRelative(iso)}
  </span>
);

const renderCreatedAtCell = (row: User) =>
  renderRelativeCell(row.createdAt, `users-table__created-${row.id}`);
const renderUpdatedAtCell = (row: User) =>
  renderRelativeCell(row.updatedAt, `users-table__updated-${row.id}`);

function buildUsersColumns(
  visible: UserColumnKey[],
  onUserDeleteFailed: (id: string, problem: ProblemDetails) => void,
  onUserDeleted?: (id: string) => void,
): DataTableColumn<User>[] {
  const shown = new Set(visible);
  const renderActionsCell = (row: User) => (
    <UserRowActions
      id={row.id}
      email={row.email}
      surface="table"
      reveal="row"
      onUserDeleted={onUserDeleted}
      onUserDeleteFailed={onUserDeleteFailed}
      className="justify-end"
    />
  );

  const columns: DataTableColumn<User>[] = [
    {
      id: "email",
      header: "Email",
      sortable: true,
      cell: renderEmailCell,
      className: "min-w-0",
    },
  ];
  if (shown.has("roles")) {
    columns.push({
      id: "roles",
      header: "Roles",
      cell: renderRolesCell,
      className: "whitespace-nowrap",
      colClassName: "w-48",
    });
  }
  if (shown.has("status")) {
    columns.push({
      id: "status",
      header: "Status",
      sortable: true,
      cell: renderStatusCell,
      className: "whitespace-nowrap",
      colClassName: "w-28",
    });
  }
  if (shown.has("createdAt")) {
    columns.push({
      id: "createdAt",
      header: "Created",
      sortable: true,
      cell: renderCreatedAtCell,
      className: "users-table__col--lg hidden lg:table-cell",
      colClassName: "w-32 max-lg:hidden",
    });
  }
  if (shown.has("updatedAt")) {
    columns.push({
      id: "updatedAt",
      header: "Updated",
      sortable: true,
      cell: renderUpdatedAtCell,
      className: "users-table__col--xl hidden xl:table-cell",
      colClassName: "w-32 max-xl:hidden",
    });
  }
  columns.push({
    id: "actions",
    header: "Actions",
    align: "right",
    className: "users-table__col--actions",
    colClassName: "w-28",
    cell: renderActionsCell,
  });
  return columns;
}

export function UsersTable({
  users,
  visible,
  sort,
  onSortChange,
  onUserDeleted,
  onUserDeleteFailed,
  selection,
  onUserPeek,
  density = "compact",
}: Readonly<UsersTableProps>) {
  const router = useRouter();

  const columns = useMemo(
    () => buildUsersColumns(visible, onUserDeleteFailed, onUserDeleted),
    [visible, onUserDeleteFailed, onUserDeleted],
  );

  return (
    <div
      className="users-table overflow-x-auto sm:mx-0 lg:overflow-x-visible"
      data-testid="users-table"
    >
      <DataTable
        columns={columns}
        data={users}
        rowKey={(row) => row.id}
        caption="Backoffice users"
        density={density}
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        selection={selection}
        onRowActivate={(row) => router.push(safeHref(userRoutes.detail(row.id)))}
        onRowPeek={onUserPeek}
        rowTestId={(row) => `users-table__row-${row.id}`}
        testId="users-table__inner"
        layout="fixed"
        className="users-table__inner min-w-[44rem]"
      />
    </div>
  );
}
