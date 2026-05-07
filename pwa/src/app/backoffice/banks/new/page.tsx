import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { BankForm } from "../_components/BankForm";

export default function NewBankPage() {
  return (
    <div className="banks-new space-y-6">
      <header className="banks-new__header space-y-2">
        <Link
          href="/backoffice/banks"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
        >
          <ChevronLeft className="size-3" aria-hidden="true" />
          Back to banks
        </Link>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">New bank</h1>
        <p className="text-muted-foreground text-sm">Create a new bank.</p>
      </header>

      <BankForm mode="create" />
    </div>
  );
}
