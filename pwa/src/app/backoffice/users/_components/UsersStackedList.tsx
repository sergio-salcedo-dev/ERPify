"use client";

import Link from "next/link";
import type { User } from "@/context/backoffice/user/domain/User";
import { TruncatedText } from "@/components/erpify";
import { useRowKeyboardNavigation } from "@/context/shared/resource/application/useRowKeyboardNavigation";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { userRoutes } from "../_lib/userRoutes";
import { UserStatusBadge } from "./UserStatusBadge";
import { UserRowActions } from "./UserRowActions";

interface UsersStackedListProps {
  users: User[];
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  /** Enables Shift+↑/↓ range selection (Explorer semantics) when provided. */
  onSelectionChange?: (ids: Set<string>) => void;
  /** Fired when `o` is pressed on a focused row — opens the record peek. */
  onUserPeek?: (id: string) => void;
  density?: "compact" | "comfortable";
  onInvitationRevoked: () => void;
  onRevokeFailed: (problem: ProblemDetails) => void;
  className?: string;
}

/**
 * Below `md` the table becomes this stack of card-rows. Keyboard contract
 * mirrors the table: each row-card is a single roving tab stop; ↑/↓ move,
 * Enter opens the detail, `o` peeks the record. Selection is wired through
 * the optional props but no surface passes them yet, so no checkbox renders.
 */
export function UsersStackedList({
  users,
  selectedIds,
  onToggleSelect,
  onSelectionChange,
  onUserPeek,
  density = "compact",
  onInvitationRevoked,
  onRevokeFailed,
  className,
}: Readonly<UsersStackedListProps>) {
  const { focusedRow, setFocusedRow, rowRefs, handleKeyDown } = useRowKeyboardNavigation<User>({
    items: users,
    selectedIds,
    onSelectionChange,
    onToggleSelect,
    onPeek: onUserPeek,
  });

  // Roving tab stop, clamped so an optimistic delete that shrinks the list never
  // leaves the active index past the last row (which would drop keyboard focus to
  // <body>). onFocus re-syncs it once focus lands on a real row.
  const activeRow = Math.min(focusedRow, users.length - 1);

  return (
    <ul
      aria-label="Users"
      className={cn("users-stacked flex list-none flex-col gap-2 p-0", className)}
      data-testid="users-stacked"
    >
      {users.map((user, index) => {
        const selected = selectedIds?.has(user.id) ?? false;
        return (
          <li
            key={user.id}
            className={cn(
              "users-stacked__row border-border bg-card hover:bg-muted relative flex items-center gap-3 rounded-md border transition-colors motion-reduce:transition-none",
              density === "comfortable" ? "p-3" : "p-2",
              selected && "bg-row-selected",
            )}
          >
            {onToggleSelect ? (
              <input
                type="checkbox"
                aria-label={`Select user ${user.email}`}
                checked={selected}
                onChange={() => onToggleSelect(user.id)}
                className="users-stacked__select accent-primary border-border relative z-10 size-4 flex-none cursor-pointer rounded"
                data-testid={`users-stacked__select-${user.id}`}
              />
            ) : null}
            <div className="min-w-0 flex-1">
              <div className="flex min-w-0 items-center gap-2">
                <UserStatusBadge status={user.status} />
              </div>
              <Link
                ref={(el) => {
                  rowRefs.current[index] = el;
                }}
                href={safeHref(userRoutes.detail(user.id))}
                tabIndex={activeRow === index ? 0 : -1}
                onFocus={() => setFocusedRow(index)}
                onKeyDown={(event) => handleKeyDown(event, index)}
                data-stacked-row
                data-testid={`users-stacked__row-${user.id}`}
                className="users-stacked__link focus-visible:after:ring-ring block min-w-0 after:absolute after:inset-0 after:rounded-md focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset"
              >
                <TruncatedText
                  value={user.email}
                  className="users-stacked__email relative z-10 mt-0.5 text-sm font-medium"
                  focusScopeSelector="[data-stacked-row]"
                />
              </Link>
            </div>
            <UserRowActions
              id={user.id}
              email={user.email}
              status={user.status}
              surface="stacked"
              onInvitationRevoked={onInvitationRevoked}
              onRevokeFailed={onRevokeFailed}
              className="relative z-10 flex-none"
            />
          </li>
        );
      })}
    </ul>
  );
}
