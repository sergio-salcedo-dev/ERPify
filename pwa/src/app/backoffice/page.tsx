"use client";

import { Users, Building2, TrendingUp, Clock } from "lucide-react";
import { StatCard } from "@/context/shared/infrastructure/ui/components/molecules/StatCard";
import { PlaceholderCard } from "@/context/shared/infrastructure/ui/components/molecules/PlaceholderCard";

export default function BackOfficeDashboard() {
  const stats = [
    {
      name: "Active Projects",
      value: "24",
      icon: Building2,
      color: "text-primary",
      bg: "bg-primary/10",
    },
    {
      name: "Total Workforce",
      value: "156",
      icon: Users,
      color: "text-success",
      bg: "bg-success/10",
    },
    {
      name: "Revenue Growth",
      value: "+12.5%",
      icon: TrendingUp,
      color: "text-warning",
      bg: "bg-warning/10",
    },
    {
      name: "Pending Tasks",
      value: "48",
      icon: Clock,
      color: "text-destructive",
      bg: "bg-destructive/10",
    },
  ];

  return (
    <div className="dashboard space-y-10">
      <header className="dashboard__header flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="dashboard__header-info">
          <h1 className="dashboard__title text-foreground text-2xl font-semibold tracking-tight">
            Dashboard
          </h1>
          <p className="dashboard__subtitle text-muted-foreground mt-1 text-sm">
            Welcome back, Admin. Here&apos;s what&apos;s happening today.
          </p>
        </div>
      </header>

      {/* Stats Grid */}
      <div className="dashboard__stats grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map((stat, index) => (
          <StatCard key={stat.name} {...stat} index={index} />
        ))}
      </div>

      {/* Placeholder for more content */}
      <div className="dashboard__placeholders grid grid-cols-1 lg:grid-cols-2 gap-8">
        <PlaceholderCard
          title="Project Timeline"
          description="Detailed project tracking and Gantt charts will appear here."
          icon={Building2}
        />
        <PlaceholderCard
          title="Resource Allocation"
          description="Manage your machinery and workforce distribution across sites."
          icon={Users}
        />
      </div>
    </div>
  );
}
