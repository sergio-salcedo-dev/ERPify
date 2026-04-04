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
      color: "text-blue-600",
      bg: "bg-blue-50",
    },
    {
      name: "Total Workforce",
      value: "156",
      icon: Users,
      color: "text-emerald-600",
      bg: "bg-emerald-50",
    },
    {
      name: "Revenue Growth",
      value: "+12.5%",
      icon: TrendingUp,
      color: "text-amber-600",
      bg: "bg-amber-50",
    },
    { name: "Pending Tasks", value: "48", icon: Clock, color: "text-rose-600", bg: "bg-rose-50" },
  ];

  return (
    <div className="dashboard space-y-10">
      <header className="dashboard__header flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="dashboard__header-info">
          <h1 className="dashboard__title text-3xl font-extrabold text-slate-900 tracking-tight">
            Dashboard
          </h1>
          <p className="dashboard__subtitle text-slate-500 font-medium mt-1">
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
