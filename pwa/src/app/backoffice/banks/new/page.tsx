import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";
import { bankRoutes } from "../_lib/bankRoutes";
import { BankForm } from "../_components/BankForm";

export default function NewBankPage() {
  return (
    <div
      className="banks-new mx-auto w-full max-w-screen-md space-y-4 sm:space-y-6"
      data-testid="banks-new"
    >
      <header className="banks-new__header space-y-2" data-testid="banks-new__header">
        <Link
          href={bankRoutes.list}
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
          aria-label="Back to banks"
          title="Back to banks"
          data-testid="banks-new__back-link"
        >
          <ChevronLeft className="size-3" aria-hidden="true" />
          Back to banks
        </Link>
        <h1
          className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
          data-testid="banks-new__title"
        >
          New bank
        </h1>
        <p className="text-muted-foreground text-sm" data-testid="banks-new__subtitle">
          Create a new bank.
        </p>
      </header>

      <BankForm mode={PersistenceAction.CREATING} />
    </div>
  );
}
