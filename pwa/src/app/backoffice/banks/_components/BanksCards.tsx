"use client";

import Link from "next/link";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { StatusBadge } from "@/components/erpify";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { bankRoutes } from "../_lib/bankRoutes";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { BankRowActions } from "./BankRowActions";

interface BanksCardsProps {
  banks: Bank[];
  onBankDeleted?: (id: string) => void;
  selectedIds?: ReadonlySet<string>;
  onToggleSelect?: (id: string) => void;
}

export function BanksCards({
  banks,
  onBankDeleted,
  selectedIds,
  onToggleSelect,
}: Readonly<BanksCardsProps>) {
  return (
    <ul
      className="banks-cards grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
      data-testid="banks-cards"
    >
      {banks.map((bank) => {
        const detailHref = safeHref(bankRoutes.detail(bank.id));
        const selected = selectedIds?.has(bank.id) ?? false;
        return (
          <li
            key={bank.id}
            className="banks-cards__item"
            data-testid={`banks-cards__item-${bank.id}`}
          >
            {/*
             * `relative` anchors the name link's stretched `::after` overlay so
             * the whole card navigates to the detail page; the action cluster
             * and the selection checkbox sit at `z-10` above that overlay so
             * their controls stay clickable.
             */}
            <Card
              size="sm"
              className={cn(
                "banks-cards__card relative h-full transition-shadow hover:shadow-elevation-1 hover:ring-foreground/20",
                selected && "ring-2 ring-primary",
              )}
            >
              <CardHeader>
                <div className="banks-cards__identity flex min-w-0 items-start gap-2.5">
                  {onToggleSelect ? (
                    <input
                      type="checkbox"
                      aria-label={`Select bank ${bank.name}`}
                      checked={selected}
                      onChange={() => onToggleSelect(bank.id)}
                      className="banks-cards__select accent-primary border-border relative z-10 mt-1 size-4 flex-none cursor-pointer rounded opacity-0 transition-opacity group-hover/card:opacity-100 checked:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
                      data-testid={`banks-cards__select-${bank.id}`}
                    />
                  ) : null}
                  <div className="min-w-0 flex-1 space-y-1">
                    <CardTitle className="banks-cards__title">
                      <Link
                        href={detailHref}
                        className="banks-cards__name font-semibold text-foreground [overflow-wrap:anywhere] hover:underline focus-visible:underline focus-visible:outline-none after:absolute after:inset-0"
                        title={`View bank ${bank.name}`}
                        data-testid={`banks-cards__name-${bank.id}`}
                      >
                        {bank.name}
                      </Link>
                    </CardTitle>
                    <CardDescription
                      className="banks-cards__shortname font-mono text-xs uppercase [overflow-wrap:anywhere]"
                      title={bank.shortName}
                      data-testid={`banks-cards__shortname-${bank.id}`}
                    >
                      {bank.shortName}
                    </CardDescription>
                    {isRecentlyCreated(bank.createdAt, dateTimeProvider) ? (
                      <StatusBadge
                        variant="success"
                        label="New"
                        className="banks-cards__new mt-0.5"
                        testId={`banks-cards__new-${bank.id}`}
                      />
                    ) : null}
                  </div>
                </div>
                <CardAction>
                  <BankRowActions
                    id={bank.id}
                    name={bank.name}
                    surface="cards"
                    onBankDeleted={onBankDeleted}
                    className="banks-cards__actions relative z-10 opacity-0 transition-opacity group-hover/card:opacity-100 group-focus-within/card:opacity-100 [@media(hover:none)]:opacity-100"
                  />
                </CardAction>
              </CardHeader>
              <CardContent>
                <dl className="banks-cards__meta grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs tabular-nums">
                  <dt className="text-muted-foreground">Updated</dt>
                  <dd
                    className="banks-cards__updated text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
                    data-testid={`banks-cards__updated-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(bank.updatedAt)}
                  </dd>
                  <dt className="text-muted-foreground">Created</dt>
                  <dd
                    className="banks-cards__created text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
                    data-testid={`banks-cards__created-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(bank.createdAt)}
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
