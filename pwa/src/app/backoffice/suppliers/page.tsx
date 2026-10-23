import type { Metadata } from "next";
import { Handshake, Plus } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Suppliers",
};

export default function SuppliersPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Suppliers</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Manage material vendors, subcontractor accounts, and purchasing contacts.
        </p>
      </div>

      <EmptyState
        variant="first-run"
        icon={Handshake}
        heading="No suppliers registered yet"
        description="Register vendor contacts and subcontractors to streamline purchase orders and procurement workflows."
        action={
          <Button
            type="button"
            variant="default"
            size="sm"
            className="gap-2"
            disabled
            title="Supplier creation coming soon"
          >
            <Plus className="size-4" aria-hidden="true" />
            Add Supplier
          </Button>
        }
      />
    </div>
  );
}
