"use client";

import { LucideIcon } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface StatCardProps {
  name: string;
  value: string;
  icon: LucideIcon;
  color: string;
  bg: string;
  index: number;
}

export function StatCard({ name, value, icon: Icon, color, bg, index }: Readonly<StatCardProps>) {
  return (
    <div
      className="stat-card animate-in fade-in-0 slide-in-from-bottom-4 duration-500"
      style={{ animationDelay: `${index * 100}ms`, animationFillMode: "both" }}
    >
      <Card className="stat-card__container bg-card rounded-2xl border border-border shadow-sm hover:shadow-md transition-shadow">
        <CardContent className="stat-card__content p-6">
          <div className={cn("stat-card__icon-wrapper p-3 rounded-xl w-fit mb-4", bg, color)}>
            <Icon className="stat-card__icon w-6 h-6" />
          </div>
          <p className="stat-card__name text-muted-foreground font-semibold text-xs uppercase tracking-wider">
            {name}
          </p>
          <p className="stat-card__value text-3xl font-bold text-foreground mt-1">{value}</p>
        </CardContent>
      </Card>
    </div>
  );
}
