import type { Metadata } from "next";
import { HardHat, Plus } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = {
  title: "Project Management",
};

export default function ProjectsPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">
          Project Management
        </h1>
        <p className="text-muted-foreground text-sm mt-1">
          Oversee construction works, track project milestones, site assignments, and execution
          timelines.
        </p>
      </div>

      <EmptyState
        variant="first-run"
        icon={HardHat}
        heading="No active projects"
        description="Create a project to assign site teams, monitor budgets, and log work progress."
        action={
          <Button
            type="button"
            variant="default"
            size="sm"
            className="gap-2"
            disabled
            title="Project creation coming soon"
          >
            <Plus className="size-4" aria-hidden="true" />
            Create Project
          </Button>
        }
      />
    </div>
  );
}
