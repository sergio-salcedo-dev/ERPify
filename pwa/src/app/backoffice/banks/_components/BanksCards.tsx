"use client";

import Link from "next/link";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { StatusBadge, TruncatedText } from "@/components/erpify";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { useIsTruncated } from "@/lib/useIsTruncated";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { bankRoutes } from "../_lib/bankRoutes";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { BankRowActions } from "./BankRowActions";

interface BanksCardsProps {
  banks: Bank[];
  onBankDeleted?: (id: string) => void;
  onBankDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
  density?: "compact" | "comfortable";
}

/**
 * Card title doubles as the whole-card navigation overlay (stretched
 * `::after`). The full-value tooltip mounts only when the 2-line clamp
 * actually truncates; the link is the card's tab stop, so the tooltip opens
 * on keyboard focus for free.
 */
function BankCardName({ bank, detailHref }: Readonly<{ bank: Bank; detailHref: string }>) {
  const { ref, truncated } = useIsTruncated<HTMLAnchorElement>(bank.name);
  // min-h reserves exactly 2 clamped lines: 2 × leading-[1.35] = 2.7em
  const link = (
    <Link
      ref={ref}
      href={detailHref}
      className="banks-cards__name text-foreground line-clamp-2 block min-h-[2.7em] leading-[1.35] font-semibold [overflow-wrap:anywhere] after:absolute after:inset-0 hover:underline focus-visible:underline focus-visible:outline-none"
      data-testid={`banks-cards__name-${bank.id}`}
    >
      {bank.name}
    </Link>
  );
  if (!truncated) return link;
  return (
    <Tooltip>
      <TooltipTrigger render={link} />
      <TooltipContent className="max-h-28 max-w-[360px] overflow-y-auto break-words whitespace-pre-wrap">
        {bank.name}
      </TooltipContent>
    </Tooltip>
  );
}

export function BanksCards({
  banks,
  onBankDeleted,
  onBankDeleteFailed,
  selectedIds,
  onToggleSelect,
  density = "compact",
}: Readonly<BanksCardsProps>) {
  return (
    <ul
      className="banks-cards grid list-none auto-rows-fr grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
      data-testid="banks-cards"
    >
      {banks.map((bank) => {
        const detailHref = safeHref(bankRoutes.detail(bank.id));
        const selected = selectedIds?.has(bank.id) ?? false;
        const recent = isRecentlyCreated(bank.createdAt, dateTimeProvider);
        return (
          <li
            key={bank.id}
            className="banks-cards__item h-full"
            data-testid={`banks-cards__item-${bank.id}`}
          >
            {/*
             * Fixed regions, top to bottom: (1) controls — always-visible
             * checkbox + mono code + actions, (2) full-width title with a
             * reserved 2-line clamp, (3) status, (4) meta footer anchored to
             * the bottom. Controls and data never share a row. `relative`
             * anchors the title link's stretched overlay; checkbox, code and
             * actions sit at `z-10` above it so the controls stay clickable
             * and the code's full-value tooltip can receive hover.
             */}
            <Card
              size={density === "comfortable" ? "default" : "sm"}
              className={cn(
                "banks-cards__card hover:shadow-elevation-1 hover:ring-foreground/20 relative flex h-full flex-col transition-shadow motion-reduce:transition-none",
                selected && "ring-primary bg-row-selected ring-2",
              )}
            >
              <div className="banks-cards__controls flex min-w-0 items-center gap-2 px-4 group-data-[size=sm]/card:px-3">
                {onToggleSelect ? (
                  <input
                    type="checkbox"
                    aria-label={`Select bank ${bank.name}`}
                    checked={selected}
                    onChange={() => onToggleSelect(bank.id)}
                    className="banks-cards__select accent-primary border-border relative z-10 size-4 flex-none cursor-pointer rounded"
                    data-testid={`banks-cards__select-${bank.id}`}
                  />
                ) : null}
                {/*
                 * `relative z-10` lifts the code above the overlay so hover
                 * can open its tooltip (unlike the table cell, which has no
                 * overlay and must not gain a z-index). Focus stays
                 * hover-only: the card's tab stop is the title link, which
                 * owns the name tooltip.
                 */}
                <TruncatedText
                  value={bank.shortName}
                  className="banks-cards__shortname relative z-10 font-mono text-xs font-medium uppercase"
                  openOnRowFocus={false}
                  testId={`banks-cards__shortname-${bank.id}`}
                />
                <BankRowActions
                  id={bank.id}
                  name={bank.name}
                  surface="cards"
                  accountCount={bank.accountCount}
                  reveal="card"
                  onBankDeleted={onBankDeleted}
                  onBankDeleteFailed={onBankDeleteFailed}
                  className="banks-cards__actions relative z-10 ml-auto"
                />
              </div>
              <div className="banks-cards__title px-4 group-data-[size=sm]/card:px-3">
                <BankCardName bank={bank} detailHref={detailHref} />
              </div>
              <div className="banks-cards__status px-4 group-data-[size=sm]/card:px-3">
                {recent ? (
                  <StatusBadge
                    variant="success"
                    label="New"
                    className="banks-cards__new"
                    testId={`banks-cards__new-${bank.id}`}
                  />
                ) : (
                  <StatusBadge variant="neutral" label="Active" />
                )}
              </div>
              <CardContent className="banks-cards__footer border-border mt-auto border-t pt-3">
                <dl className="banks-cards__meta grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs tabular-nums">
                  <dt className="text-muted-foreground">Updated</dt>
                  <dd
                    className="banks-cards__updated text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
                    data-testid={`banks-cards__updated-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(bank.updatedAt)}
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
