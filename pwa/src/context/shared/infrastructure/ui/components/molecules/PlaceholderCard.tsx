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
    <Card className="placeholder-card bg-card rounded-2xl border border-border shadow-sm min-h-[300px] flex flex-col items-center justify-center text-center overflow-hidden">
      <CardContent className="placeholder-card__content p-8 flex flex-col items-center justify-center">
        <div className="placeholder-card__icon-wrapper bg-muted p-6 rounded-full mb-4">
          <Icon className="placeholder-card__icon w-12 h-12 text-muted-foreground" />
        </div>
        <h3 className="placeholder-card__title text-xl font-semibold text-foreground">{title}</h3>
        <p className="placeholder-card__description text-muted-foreground max-w-xs mt-2 text-sm">
          {description}
        </p>
      </CardContent>
    </Card>
  );
};
