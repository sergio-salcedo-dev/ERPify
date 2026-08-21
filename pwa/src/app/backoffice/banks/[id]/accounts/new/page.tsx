import type { Metadata } from "next";
import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";
import { BankAccountForm } from "../_components/BankAccountForm";

// Next's route announcer reads `document.title` and speaks only when it changes, so a route
// inheriting the root title arrives unnamed for assistive technology.
export const metadata: Metadata = {
  title: "New account",
};

export default async function NewBankAccountPage({
  params,
}: Readonly<{ params: Promise<{ id: string }> }>) {
  const { id: bankId } = await params;

  return (
    <div
      className="bank-accounts-new mx-auto w-full max-w-screen-md space-y-4 sm:space-y-6"
      data-testid="bank-accounts-new"
    >
      <header
        className="bank-accounts-new__header space-y-2"
        data-testid="bank-accounts-new__header"
      >
        <Link
          href={safeHref(bankAccountRoutes.list(bankId))}
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
          aria-label="Back to accounts"
          title="Back to accounts"
          data-testid="bank-accounts-new__back-link"
        >
          <ChevronLeft className="size-3" aria-hidden="true" />
          Back to accounts
        </Link>
        <h1
          className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
          data-testid="bank-accounts-new__title"
        >
          New account
        </h1>
        <p className="text-muted-foreground text-sm" data-testid="bank-accounts-new__subtitle">
          Create a new account for this bank.
        </p>
      </header>

      <BankAccountForm mode={PersistenceAction.CREATING} bankId={bankId} />
    </div>
  );
}
