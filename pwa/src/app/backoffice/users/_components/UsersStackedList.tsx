"use client";

import { useEffect, useRef, useState, type KeyboardEvent } from "react";
import Link from "next/link";
import type { User } from "@/context/backoffice/user/domain/User";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { TruncatedText } from "@/components/erpify";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
import { userRoutes } from "../_lib/userRoutes";
import { UserStatusBadge } from "./UserStatusBadge";
import { UserRowActions } from "./UserRowActions";

interface UsersStackedListProps {
  users: User[];
  onUserDeleted?: (id: string) => void;
  onUserDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  /** Enables Shift+↑/↓ range selection (Explorer semantics) when provided. */
  onSelectionChange?: (ids: Set<string>) => void;
  /** Fired when `o` is pressed on a focused row — opens the record peek. */
  onUserPeek?: (id: string) => void;
  density?: "compact" | "comfortable";
  className?: string;
}

/**
 * Below `md` the table becomes this stack of card-rows. Keyboard contract
 * mirrors the table: each row-card is a single roving tab stop; ↑/↓ move
 * (Shift extends the selection range), Enter opens the detail, `o` peeks the
 * record, Space toggles selection. Checkbox and actions are always visible.
 */
export function UsersStackedList({
  users,
  onUserDeleted,
  onUserDeleteFailed,
  selectedIds,
  onToggleSelect,
  onSelectionChange,
  onUserPeek,
  density = "compact",
  className,
}: Readonly<UsersStackedListProps>) {
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLAnchorElement | null>>([]);
  const rangeRef = useRef<{ anchor: number; baseline: ReadonlySet<string> } | null>(null);
  const rangeEmittedRef = useRef<ReadonlySet<string> | null>(null);

  useEffect(() => {
    rangeRef.current = null;
  }, [users]);

  useEffect(() => {
    if (selectedIds !== rangeEmittedRef.current) {
      rangeRef.current = null;
    }
  }, [selectedIds]);

  const extendRange = (fromIndex: number, toIndex: number): void => {
    if (!onSelectionChange) return;
    if (rangeRef.current === null) {
      rangeRef.current = { anchor: fromIndex, baseline: new Set(selectedIds ?? []) };
    }
    const { anchor, baseline } = rangeRef.current;
    const lo = Math.min(anchor, toIndex);
    const hi = Math.max(anchor, toIndex);
    const next = new Set(baseline);
    for (let i = lo; i <= hi; i++) {
      next.add(users[i].id);
    }
    rangeEmittedRef.current = next;
    onSelectionChange(next);
  };

  const handleKeyDown = (
    event: KeyboardEvent<HTMLAnchorElement>,
    user: User,
    index: number,
  ): void => {
    if (event.key === KeyboardKey.ARROW_DOWN) {
      event.preventDefault();
      const next = Math.min(index + 1, users.length - 1);
      if (event.shiftKey && onSelectionChange) {
        extendRange(index, next);
      } else {
        rangeRef.current = null;
      }
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    if (event.key === KeyboardKey.ARROW_UP) {
      event.preventDefault();
      const next = Math.max(index - 1, 0);
      if (event.shiftKey && onSelectionChange) {
        extendRange(index, next);
      } else {
        rangeRef.current = null;
      }
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    if (event.key.toLowerCase() === KeyboardKey.O && !event.shiftKey && onUserPeek) {
      event.preventDefault();
      rangeRef.current = null;
      onUserPeek(user.id);
      return;
    }
    if (event.key === KeyboardKey.SPACE && onToggleSelect) {
      event.preventDefault();
      rangeRef.current = null;
      onToggleSelect(user.id);
    }
  };

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
                tabIndex={focusedRow === index ? 0 : -1}
                onFocus={() => setFocusedRow(index)}
                onKeyDown={(event) => handleKeyDown(event, user, index)}
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
              surface="stacked"
              onUserDeleted={onUserDeleted}
              onUserDeleteFailed={onUserDeleteFailed}
              className="relative z-10 flex-none"
            />
          </li>
        );
      })}
    </ul>
  );
}
