import type { Metadata } from "next";
import { FileText, Plus } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Quotes",
};

export default function QuotesPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Quotes</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Prepare commercial proposals, price estimates, and sales quotations for prospective
          clients.
        </p>
      </div>

      <EmptyState
        variant="first-run"
        icon={FileText}
        heading="No quotations generated"
        description="Draft custom quotes using catalog price lists and technical study costings."
        action={
          <Button
            type="button"
            variant="default"
            size="sm"
            className="gap-2"
            disabled
            title="Quote creation coming soon"
          >
            <Plus className="size-4" aria-hidden="true" />
            Create Quote
          </Button>
        }
      />
    </div>
  );
}
