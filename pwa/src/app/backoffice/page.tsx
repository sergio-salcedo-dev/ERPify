import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Dashboard",
};

export default function BackOfficeDashboard() {
  return (
    <div className="dashboard">
      <h1 className="dashboard__title text-foreground text-2xl font-semibold tracking-tight">
        Dashboard
      </h1>

      <div className="dashboard__empty flex min-h-[60vh] flex-col items-center justify-center gap-2 text-center">
        <p className="dashboard__empty-lead text-foreground text-base font-semibold">
          No metrics to show yet
        </p>
        <p className="dashboard__empty-detail text-muted-foreground max-w-prose text-sm">
          Operational figures — costs, profit, active projects — will appear here as you add data to
          the system.
        </p>
      </div>
    </div>
  );
}
