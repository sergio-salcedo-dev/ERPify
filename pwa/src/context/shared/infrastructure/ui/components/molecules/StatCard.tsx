import React from "react";
import { motion } from "motion/react";
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

export const StatCard: React.FC<StatCardProps> = ({
  name,
  value,
  icon: Icon,
  color,
  bg,
  index,
}) => {
  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.1 }}
      className="stat-card"
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
    </motion.div>
  );
};
