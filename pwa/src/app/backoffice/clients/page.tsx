import type { Metadata } from "next";
import { Users, UserPlus } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Clients",
};

export default function ClientsPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Clients</h1>
        <p className="text-muted-foreground text-sm mt-1">
          Manage customer profiles, contact persons, and client relationships across sales
          operations.
        </p>
      </div>

      <EmptyState
        variant="first-run"
        icon={Users}
        heading="No clients registered yet"
        description="Add customer profiles to start tracking sales leads, project proposals, and invoicing histories."
        action={
          <Button
            type="button"
            variant="default"
            size="sm"
            className="gap-2"
            disabled
            title="Client creation coming soon"
          >
            <UserPlus className="size-4" aria-hidden="true" />
            Add New Client
          </Button>
        }
      />
    </div>
  );
}
