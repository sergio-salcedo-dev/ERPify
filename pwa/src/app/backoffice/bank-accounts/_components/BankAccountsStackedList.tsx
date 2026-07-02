"use client";

import Link from "next/link";
import { StatusBadge, TruncatedText, type ListDensity } from "@/components/erpify";
import { useRowKeyboardNavigation } from "@/context/shared/resource/application/useRowKeyboardNavigation";
import { cn } from "@/components/cn";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { IbanCell } from "../../banks/[id]/accounts/_components/IbanCell";
import { BankAccountRowActions } from "../../banks/[id]/accounts/_components/BankAccountRowActions";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";
import { BANK_ACCOUNT_STATUS_LABEL, BANK_ACCOUNT_STATUS_VARIANT } from "../_lib/bankAccountStatus";
import { useIbanReveal } from "./useIbanReveal";

interface BankAccountsStackedListProps {
  accounts: BankAccountCollectionRow[];
  onAccountDeleted?: (id: string) => void;
  onAccountDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  /** Enables Shift+↑/↓ range selection (Explorer semantics) when provided. */
  onSelectionChange?: (ids: Set<string>) => void;
  density?: ListDensity;
  className?: string;
}

/**
 * Below `md` the table becomes this stack of card-rows — zero horizontal scroll
 * on mobile. The holder link is the roving tab stop (↑/↓ move, Shift extends the
 * selection, Enter opens the detail); the IBAN reveal/copy and the row actions
 * are independent controls (no stretched card overlay, so they stay clickable).
 * The IBAN reveal is single-flight across the list and re-masks on page change.
 */
export function BankAccountsStackedList({
  accounts,
  onAccountDeleted,
  onAccountDeleteFailed,
  selectedIds,
  onToggleSelect,
  onSelectionChange,
  density = "compact",
  className,
}: Readonly<BankAccountsStackedListProps>) {
  const { revealedId, reveal, hide } = useIbanReveal(accounts);
  const { focusedRow, setFocusedRow, rowRefs, handleKeyDown } =
    useRowKeyboardNavigation<BankAccountCollectionRow>({
      items: accounts,
      selectedIds,
      onSelectionChange,
      onToggleSelect,
    });

  // Roving tab stop, clamped so an optimistic delete never leaves the active
  // index past the last row (which would drop keyboard focus to <body>).
  const activeRow = Math.min(focusedRow, accounts.length - 1);

  return (
    <ul
      aria-label="Bank accounts"
      className={cn("bank-accounts-stacked flex list-none flex-col gap-2 p-0", className)}
      data-testid="bank-accounts-stacked"
    >
      {accounts.map((account, index) => {
        const selected = selectedIds?.has(account.id) ?? false;
        return (
          <li
            key={account.id}
            className={cn(
              "bank-accounts-stacked__row border-border bg-card flex flex-col gap-2 rounded-md border transition-colors motion-reduce:transition-none",
              density === "comfortable" ? "p-3" : "p-2",
              selected && "bg-row-selected",
            )}
          >
            <div className="flex items-center gap-3">
              {onToggleSelect ? (
                <input
                  type="checkbox"
                  aria-label={`Select account ${account.holderName}`}
                  checked={selected}
                  onChange={() => onToggleSelect(account.id)}
                  className="bank-accounts-stacked__select accent-primary border-border size-4 flex-none cursor-pointer rounded"
                  data-testid={`bank-accounts-stacked__select-${account.id}`}
                />
              ) : null}
              <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-2">
                  <span className="bank-accounts-stacked__bank-code text-muted-foreground max-w-24 flex-none truncate font-mono text-xs font-medium uppercase">
                    {account.bankShortName}
                  </span>
                  <StatusBadge
                    variant={BANK_ACCOUNT_STATUS_VARIANT[account.status]}
                    label={BANK_ACCOUNT_STATUS_LABEL[account.status]}
                    className="flex-none"
                  />
                </div>
                <Link
                  ref={(el) => {
                    rowRefs.current[index] = el;
                  }}
                  href={safeHref(bankAccountRoutes.detail(account.id))}
                  tabIndex={activeRow === index ? 0 : -1}
                  onFocus={() => setFocusedRow(index)}
                  onKeyDown={(event) => handleKeyDown(event, index)}
                  data-stacked-row
                  data-testid={`bank-accounts-stacked__row-${account.id}`}
                  className="bank-accounts-stacked__link focus-visible:ring-ring block min-w-0 rounded focus-visible:ring-2 focus-visible:outline-none"
                >
                  <TruncatedText
                    value={account.holderName}
                    className="bank-accounts-stacked__holder mt-0.5 text-sm font-medium"
                    focusScopeSelector="[data-stacked-row]"
                  />
                </Link>
                <p className="bank-accounts-stacked__bank text-muted-foreground truncate text-xs">
                  {account.bankName}
                </p>
              </div>
              <BankAccountRowActions
                id={account.id}
                bankId={account.bankId}
                holderName={account.holderName}
                status={account.status}
                onAccountDeleted={onAccountDeleted}
                onAccountDeleteFailed={onAccountDeleteFailed}
                className="flex-none"
              />
            </div>
            <IbanCell
              iban={account.iban}
              revealed={revealedId === account.id}
              onReveal={() => reveal(account.id)}
              onHide={hide}
              testId={`bank-accounts-stacked__iban-${account.id}`}
            />
          </li>
        );
      })}
    </ul>
  );
}
