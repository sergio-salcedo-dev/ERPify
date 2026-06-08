"use client";

import { useEffect, useRef, useState, type KeyboardEvent } from "react";
import Link from "next/link";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { StatusBadge, TruncatedText } from "@/components/erpify";
import { cn } from "@/lib/utils";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
import { bankRoutes } from "../_lib/bankRoutes";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { BankRowActions } from "./BankRowActions";

interface BanksStackedListProps {
  banks: Bank[];
  onBankDeleted?: (id: string) => void;
  onBankDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  /** Enables Shift+↑/↓ range selection (Explorer semantics) when provided. */
  onSelectionChange?: (ids: Set<string>) => void;
  /** Fired when `o` is pressed on a focused row — opens the record peek. */
  onBankPeek?: (id: string) => void;
  density?: "compact" | "comfortable";
  className?: string;
}

/**
 * Below `md` the table becomes this stack of card-rows — horizontal scroll is
 * zero-scan on mobile. Keyboard contract mirrors the table: each row-card is
 * a single roving tab stop; ↑/↓ move (Shift extends the selection range),
 * Enter opens the detail, `o` peeks the record, Space toggles selection.
 * Checkbox and actions are always visible (coarse pointers have no hover).
 *
 * The list keeps native ul/li semantics, and interactivity lives on real
 * controls: the roving tab stop is the bank-name link, stretched over the
 * whole card (::after overlay) so any click opens the detail — Enter is
 * native link activation. The checkbox, the row actions, and the truncated
 * name sit above the overlay (relative z-10) as independent pointer targets.
 */
export function BanksStackedList({
  banks,
  onBankDeleted,
  onBankDeleteFailed,
  selectedIds,
  onToggleSelect,
  onSelectionChange,
  onBankPeek,
  density = "compact",
  className,
}: Readonly<BanksStackedListProps>) {
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLAnchorElement | null>>([]);
  // Explorer-style range selection — mirrors DataTable: `anchor` pivots the
  // range, `baseline` snapshots the selection at the first Shift+Arrow press,
  // each press recomputes `baseline ∪ range`. Reset on any non-shift
  // navigation or toggle, re-armed lazily on the next Shift+Arrow.
  const rangeRef = useRef<{ anchor: number; baseline: ReadonlySet<string> } | null>(null);
  const rangeEmittedRef = useRef<ReadonlySet<string> | null>(null);

  // The cached anchor indexes into `banks`: any external mutation of the row
  // slice (pagination, filtering, realtime deletes) invalidates it.
  useEffect(() => {
    rangeRef.current = null;
  }, [banks]);

  // An external selection change (bulk-bar Clear, checkbox click, remote
  // pruning) also ends the range; only the set extendRange itself emitted
  // keeps the anchor alive.
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
      next.add(banks[i].id);
    }
    rangeEmittedRef.current = next;
    onSelectionChange(next);
  };

  const handleKeyDown = (
    event: KeyboardEvent<HTMLAnchorElement>,
    bank: Bank,
    index: number,
  ): void => {
    if (event.key === KeyboardKey.ARROW_DOWN) {
      event.preventDefault();
      const next = Math.min(index + 1, banks.length - 1);
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
    // Case-insensitive so CapsLock can't disable the peek; Shift stays
    // reserved for range selection.
    if (event.key.toLowerCase() === KeyboardKey.O && !event.shiftKey && onBankPeek) {
      event.preventDefault();
      rangeRef.current = null;
      onBankPeek(bank.id);
      return;
    }
    if (event.key === KeyboardKey.SPACE && onToggleSelect) {
      event.preventDefault();
      rangeRef.current = null;
      onToggleSelect(bank.id);
    }
  };

  return (
    <ul
      aria-label="Banks"
      className={cn("banks-stacked flex list-none flex-col gap-2 p-0", className)}
      data-testid="banks-stacked"
    >
      {banks.map((bank, index) => {
        const selected = selectedIds?.has(bank.id) ?? false;
        const recent = isRecentlyCreated(bank.createdAt, dateTimeProvider);
        return (
          <li
            key={bank.id}
            className={cn(
              "banks-stacked__row border-border bg-card hover:bg-muted relative flex items-center gap-3 rounded-md border transition-colors motion-reduce:transition-none",
              density === "comfortable" ? "p-3" : "p-2",
              selected && "bg-row-selected",
            )}
          >
            {onToggleSelect ? (
              <input
                type="checkbox"
                aria-label={`Select bank ${bank.name}`}
                checked={selected}
                onChange={() => onToggleSelect(bank.id)}
                className="banks-stacked__select accent-primary border-border relative z-10 size-4 flex-none cursor-pointer rounded"
                data-testid={`banks-stacked__select-${bank.id}`}
              />
            ) : null}
            <div className="min-w-0 flex-1">
              <div className="flex min-w-0 items-center gap-2">
                <span className="banks-stacked__code text-muted-foreground max-w-24 flex-none truncate font-mono text-xs font-medium uppercase">
                  {bank.shortName}
                </span>
                {recent ? (
                  <StatusBadge variant="success" label="New" className="flex-none" />
                ) : null}
              </div>
              <Link
                ref={(el) => {
                  rowRefs.current[index] = el;
                }}
                href={safeHref(bankRoutes.detail(bank.id))}
                tabIndex={focusedRow === index ? 0 : -1}
                onFocus={() => setFocusedRow(index)}
                onKeyDown={(event) => handleKeyDown(event, bank, index)}
                data-stacked-row
                data-testid={`banks-stacked__row-${bank.id}`}
                className="banks-stacked__link focus-visible:after:ring-ring block min-w-0 after:absolute after:inset-0 after:rounded-md focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset"
              >
                <TruncatedText
                  value={bank.name}
                  className="banks-stacked__name relative z-10 mt-0.5 text-sm font-medium"
                  focusScopeSelector="[data-stacked-row]"
                />
              </Link>
            </div>
            <BankRowActions
              id={bank.id}
              name={bank.name}
              surface="stacked"
              onBankDeleted={onBankDeleted}
              onBankDeleteFailed={onBankDeleteFailed}
              className="relative z-10 flex-none"
            />
          </li>
        );
      })}
    </ul>
  );
}
