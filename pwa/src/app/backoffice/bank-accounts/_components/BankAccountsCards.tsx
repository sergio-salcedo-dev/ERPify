"use client";

import Link from "next/link";
import { StatusBadge, TruncatedText, type ListDensity } from "@/components/erpify";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/components/cn";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { IbanCell } from "../../banks/[id]/accounts/_components/IbanCell";
import { BankAccountRowActions } from "../../banks/[id]/accounts/_components/BankAccountRowActions";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";
import { BANK_ACCOUNT_STATUS_LABEL, BANK_ACCOUNT_STATUS_VARIANT } from "../_lib/bankAccountStatus";
import { useIbanReveal } from "./useIbanReveal";

interface BankAccountsCardsProps {
  accounts: BankAccountCollectionRow[];
  onAccountDeleted?: (id: string) => void;
  onAccountDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  density?: ListDensity;
}

/**
 * Card grid of the cross-bank accounts (the alternate "cards" view). Unlike the
 * banks cards, the card is NOT a single stretched navigation overlay: the IBAN
 * reveal/copy controls are interactive mid-card, so the holder title is a plain
 * link to the detail and the interactive controls stay independently clickable.
 * The IBAN reveal is single-flight across the grid and re-masks on page change.
 */
export function BankAccountsCards({
  accounts,
  onAccountDeleted,
  onAccountDeleteFailed,
  selectedIds,
  onToggleSelect,
  density = "compact",
}: Readonly<BankAccountsCardsProps>) {
  const { revealedId, reveal, hide } = useIbanReveal(accounts);

  return (
    <ul
      className="bank-accounts-cards grid list-none auto-rows-fr grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
      data-testid="bank-accounts-cards"
    >
      {accounts.map((account) => {
        const detailHref = safeHref(bankAccountRoutes.detail(account.id));
        const selected = selectedIds?.has(account.id) ?? false;
        return (
          <li
            key={account.id}
            className="bank-accounts-cards__item h-full"
            data-testid={`bank-accounts-cards__item-${account.id}`}
          >
            <Card
              size={density === "comfortable" ? "default" : "sm"}
              className={cn(
                "bank-accounts-cards__card hover:shadow-elevation-1 hover:ring-foreground/20 flex h-full flex-col transition-shadow motion-reduce:transition-none",
                selected && "ring-primary bg-row-selected ring-2",
              )}
            >
              <div className="bank-accounts-cards__controls flex min-w-0 items-center gap-2 px-4 group-data-[size=sm]/card:px-3">
                {onToggleSelect ? (
                  <input
                    type="checkbox"
                    aria-label={`Select account ${account.holderName}`}
                    checked={selected}
                    onChange={() => onToggleSelect(account.id)}
                    className="bank-accounts-cards__select accent-primary border-border size-4 flex-none cursor-pointer rounded"
                    data-testid={`bank-accounts-cards__select-${account.id}`}
                  />
                ) : null}
                <TruncatedText
                  value={account.bankShortName}
                  className="bank-accounts-cards__bank-code text-muted-foreground font-mono text-xs font-medium uppercase"
                  openOnRowFocus={false}
                />
                <BankAccountRowActions
                  id={account.id}
                  bankId={account.bankId}
                  holderName={account.holderName}
                  status={account.status}
                  onAccountDeleted={onAccountDeleted}
                  onAccountDeleteFailed={onAccountDeleteFailed}
                  className="bank-accounts-cards__actions ml-auto"
                />
              </div>
              <div className="bank-accounts-cards__title px-4 group-data-[size=sm]/card:px-3">
                <Link
                  href={detailHref}
                  className="bank-accounts-cards__holder text-foreground block font-semibold [overflow-wrap:anywhere] hover:underline focus-visible:underline focus-visible:outline-none"
                  data-testid={`bank-accounts-cards__holder-${account.id}`}
                >
                  {account.holderName}
                </Link>
                <p className="bank-accounts-cards__bank text-muted-foreground truncate text-sm">
                  {account.bankName}
                </p>
              </div>
              <div className="bank-accounts-cards__iban px-4 group-data-[size=sm]/card:px-3">
                <IbanCell
                  iban={account.iban}
                  revealed={revealedId === account.id}
                  onReveal={() => reveal(account.id)}
                  onHide={hide}
                  testId={`bank-accounts-cards__iban-${account.id}`}
                />
              </div>
              <div className="bank-accounts-cards__status px-4 group-data-[size=sm]/card:px-3">
                <StatusBadge
                  variant={BANK_ACCOUNT_STATUS_VARIANT[account.status]}
                  label={BANK_ACCOUNT_STATUS_LABEL[account.status]}
                />
              </div>
              <CardContent className="bank-accounts-cards__footer border-border mt-auto border-t pt-3">
                <dl className="bank-accounts-cards__meta grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                  <dt className="text-muted-foreground">Alias</dt>
                  <dd className="bank-accounts-cards__alias text-foreground truncate">
                    {account.alias ?? "—"}
                  </dd>
                  <dt className="text-muted-foreground">Currency</dt>
                  <dd className="bank-accounts-cards__currency text-foreground font-mono">
                    {account.currency}
                  </dd>
                </dl>
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ul>
  );
}
