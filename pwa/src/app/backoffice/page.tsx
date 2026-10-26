import type { Metadata } from "next";
import { RecoveryRedeemedNextStep } from "./_components/RecoveryRedeemedNextStep";
import { DashboardQuickAccess } from "./_components/DashboardQuickAccess";

export const metadata: Metadata = {
  title: "Dashboard",
};

export default function BackOfficeDashboard() {
  return (
    <div className="dashboard flex flex-col gap-6">
      <h1 className="dashboard__title text-foreground text-2xl font-semibold tracking-tight">
        Dashboard
      </h1>

      <RecoveryRedeemedNextStep />

      <div className="dashboard__empty border-border flex flex-col gap-1 rounded-lg border border-dashed px-4 py-3">
        <p className="dashboard__empty-lead text-foreground text-sm font-semibold">
          No metrics to show yet
        </p>
        <p className="dashboard__empty-detail text-muted-foreground max-w-prose text-sm">
          Operational figures — costs, profit, active projects — will appear here as you add data to
          the system.
        </p>
      </div>

      <DashboardQuickAccess />
    </div>
  );
}
