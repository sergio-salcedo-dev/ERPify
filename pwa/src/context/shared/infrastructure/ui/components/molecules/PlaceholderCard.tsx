import React from "react";
import { LucideIcon } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";

interface PlaceholderCardProps {
  title: string;
  description: string;
  icon: LucideIcon;
}

export const PlaceholderCard: React.FC<PlaceholderCardProps> = ({
  title,
  description,
  icon: Icon,
}) => {
  return (
    <Card className="placeholder-card bg-white rounded-3xl border border-slate-100 shadow-sm min-h-[300px] flex flex-col items-center justify-center text-center overflow-hidden">
      <CardContent className="placeholder-card__content p-8 flex flex-col items-center justify-center">
        <div className="placeholder-card__icon-wrapper bg-slate-50 p-6 rounded-full mb-4">
          <Icon className="placeholder-card__icon w-12 h-12 text-slate-300" />
        </div>
        <h3 className="placeholder-card__title text-xl font-bold text-slate-900">{title}</h3>
        <p className="placeholder-card__description text-slate-400 max-w-xs mt-2">{description}</p>
      </CardContent>
    </Card>
  );
};
