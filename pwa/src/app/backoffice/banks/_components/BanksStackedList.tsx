"use client";

import { useRef, useState, type KeyboardEvent, type MouseEvent } from "react";
import { useRouter } from "next/navigation";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
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
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  density?: "compact" | "comfortable";
  className?: string;
}

const INTERACTIVE_DESCENDANT_SELECTOR =
  "a[href], button, input, select, textarea, [role='button'], [role='link'], [role='menuitem'], [role='checkbox']";

function isFromInteractiveControl(target: EventTarget | null, row: Element): boolean {
  if (!(target instanceof Element) || target === row) return false;
  const control = target.closest(INTERACTIVE_DESCENDANT_SELECTOR);
  return control != null && !control.contains(row);
}

/**
 * Below `md` the table becomes this stack of card-rows — horizontal scroll is
 * zero-scan on mobile. Keyboard contract mirrors the table: each row-card is
 * a single roving tab stop; ↑/↓ move, Enter opens the detail, Space toggles
 * selection. Checkbox and actions are always visible (coarse pointers have no
 * hover).
 */
export function BanksStackedList({
  banks,
  onBankDeleted,
  selectedIds,
  onToggleSelect,
  density = "compact",
  className,
}: Readonly<BanksStackedListProps>) {
  const router = useRouter();
  const [focusedRow, setFocusedRow] = useState(0);
  const rowRefs = useRef<Array<HTMLLIElement | null>>([]);

  const openDetail = (bank: Bank): void => {
    router.push(safeHref(bankRoutes.detail(bank.id)));
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLLIElement>, bank: Bank, index: number): void => {
    if (isFromInteractiveControl(event.target, event.currentTarget)) return;
    if (event.key === KeyboardKey.ARROW_DOWN) {
      event.preventDefault();
      const next = Math.min(index + 1, banks.length - 1);
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    if (event.key === KeyboardKey.ARROW_UP) {
      event.preventDefault();
      const next = Math.max(index - 1, 0);
      setFocusedRow(next);
      rowRefs.current[next]?.focus();
      return;
    }
    if (event.key === KeyboardKey.ENTER) {
      event.preventDefault();
      openDetail(bank);
      return;
    }
    if (event.key === KeyboardKey.SPACE && onToggleSelect) {
      event.preventDefault();
      onToggleSelect(bank.id);
    }
  };

  const handleClick = (event: MouseEvent<HTMLLIElement>, bank: Bank): void => {
    if (isFromInteractiveControl(event.target, event.currentTarget)) return;
    openDetail(bank);
  };

  return (
    <ul
      role="listbox"
      aria-label="Banks"
      aria-multiselectable={onToggleSelect ? true : undefined}
      className={cn("banks-stacked flex list-none flex-col gap-2 p-0", className)}
      data-testid="banks-stacked"
    >
      {banks.map((bank, index) => {
        const selected = selectedIds?.has(bank.id) ?? false;
        const recent = isRecentlyCreated(bank.createdAt, dateTimeProvider);
        return (
          <li
            key={bank.id}
            ref={(el) => {
              rowRefs.current[index] = el;
            }}
            role="option"
            aria-selected={selected}
            tabIndex={focusedRow === index ? 0 : -1}
            onFocus={() => setFocusedRow(index)}
            onKeyDown={(event) => handleKeyDown(event, bank, index)}
            onClick={(event) => handleClick(event, bank)}
            data-stacked-row
            data-testid={`banks-stacked__row-${bank.id}`}
            className={cn(
              "banks-stacked__row border-border bg-card focus-visible:ring-ring hover:bg-muted flex cursor-pointer items-center gap-3 rounded-md border transition-colors focus-visible:ring-2 focus-visible:ring-inset focus-visible:outline-none motion-reduce:transition-none",
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
                className="banks-stacked__select accent-primary border-border size-4 flex-none cursor-pointer rounded"
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
              <TruncatedText
                value={bank.name}
                className="banks-stacked__name mt-0.5 text-sm font-medium"
                focusScopeSelector="[data-stacked-row]"
              />
            </div>
            <BankRowActions
              id={bank.id}
              name={bank.name}
              surface="stacked"
              onBankDeleted={onBankDeleted}
              className="flex-none"
            />
          </li>
        );
      })}
    </ul>
  );
}
