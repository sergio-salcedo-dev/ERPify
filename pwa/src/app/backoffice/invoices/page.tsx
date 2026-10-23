import type { Metadata } from "next";
import { Receipt, Plus } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Invoicing",
};

export default function InvoicesPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Invoicing</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Issue customer billing documents, manage payment statuses, and track revenue collection.
        </p>
      </div>

      <EmptyState
        variant="first-run"
        icon={Receipt}
        heading="No invoices issued"
        description="Generate sales invoices from completed delivery notes or approved project milestones."
        action={
          <Button
            type="button"
            variant="default"
            size="sm"
            className="gap-2"
            disabled
            title="Invoice creation coming soon"
          >
            <Plus className="size-4" aria-hidden="true" />
            New Invoice
          </Button>
        }
      />
    </div>
  );
}
