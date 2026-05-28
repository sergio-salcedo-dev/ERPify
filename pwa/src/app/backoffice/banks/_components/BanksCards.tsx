"use client";

import Link from "next/link";
import { Pencil, Trash2 } from "lucide-react";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { CopyButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { cn } from "@/lib/utils";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { DeleteBankButton } from "./DeleteBankButton";

interface BanksCardsProps {
  banks: Bank[];
  onBankDeleted?: (id: string) => void;
}

export function BanksCards({ banks, onBankDeleted }: Readonly<BanksCardsProps>) {
  return (
    <ul
      className="banks-cards grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
      data-testid="banks-cards"
    >
      {banks.map((bank) => {
        const detailHref = safeHref(`/backoffice/banks/${encodeURIComponent(bank.id)}`);
        const editHref = safeHref(`/backoffice/banks/${encodeURIComponent(bank.id)}/edit`);
        return (
          <li
            key={bank.id}
            className="banks-cards__item"
            data-testid={`banks-cards__item-${bank.id}`}
          >
            <Card size="sm" className="banks-cards__card h-full">
              <CardHeader>
                <CardTitle className="banks-cards__title min-w-0 break-words">
                  <Link
                    href={detailHref}
                    className="banks-cards__name hover:underline focus-visible:underline focus-visible:outline-none"
                    title={`View bank ${bank.name}`}
                    data-testid={`banks-cards__name-${bank.id}`}
                  >
                    {bank.name}
                  </Link>
                </CardTitle>
                <CardDescription
                  className="banks-cards__shortname truncate font-mono text-xs uppercase"
                  data-testid={`banks-cards__shortname-${bank.id}`}
                >
                  {bank.shortName}
                </CardDescription>
                <CardAction>
                  <div className="banks-cards__actions flex items-center gap-1">
                    <CopyButton
                      value={bank.id}
                      iconOnly
                      size="icon-sm"
                      label="Copy ID"
                      copiedLabel="ID copied"
                      errorLabel="Copy failed"
                      title={`Copy bank ${bank.name} ID`}
                      testId={`banks-cards__copy-${bank.id}`}
                    />
                    <Link
                      href={editHref}
                      className={cn(buttonVariants({ variant: "outline", size: "icon-sm" }))}
                      aria-label="Edit"
                      title={`Edit bank ${bank.name}`}
                      data-testid={`banks-cards__edit-${bank.id}`}
                    >
                      <Pencil className="size-3.5" aria-hidden="true" />
                      <span className="sr-only">Edit</span>
                    </Link>
                    <DeleteBankButton
                      id={bank.id}
                      name={bank.name}
                      triggerTestId={`banks-cards__delete-${bank.id}`}
                      onDeleted={onBankDeleted}
                      trigger={
                        <Button
                          variant="destructive"
                          size="icon-sm"
                          aria-label="Delete"
                          title={`Delete bank ${bank.name}`}
                          data-testid={`banks-cards__delete-${bank.id}`}
                        >
                          <Trash2 className="size-3.5" aria-hidden="true" />
                          <span className="sr-only">Delete</span>
                        </Button>
                      }
                    />
                  </div>
                </CardAction>
              </CardHeader>
              <CardContent>
                <dl className="banks-cards__meta grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                  <dt className="text-muted-foreground">Created</dt>
                  <dd
                    className="banks-cards__created text-foreground"
                    data-testid={`banks-cards__created-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToDisplay(bank.createdAt)}
                  </dd>
                  <dt className="text-muted-foreground">Updated</dt>
                  <dd
                    className="banks-cards__updated text-foreground"
                    data-testid={`banks-cards__updated-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToDisplay(bank.updatedAt)}
                  </dd>
                </dl>
              </CardContent>
              <CardFooter className="banks-cards__footer justify-end py-2">
                <Link
                  href={detailHref}
                  className={cn(
                    buttonVariants({ variant: "ghost", size: "sm" }),
                    "banks-cards__view-link",
                  )}
                  title={`View bank ${bank.name}`}
                  aria-label="View"
                  data-testid={`banks-cards__view-${bank.id}`}
                >
                  View details
                </Link>
              </CardFooter>
            </Card>
          </li>
        );
      })}
    </ul>
  );
}
