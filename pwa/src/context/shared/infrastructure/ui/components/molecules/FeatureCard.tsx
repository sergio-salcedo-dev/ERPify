import React from "react";
import { motion } from "motion/react";
import { LucideIcon } from "lucide-react";
import { Button } from "../atoms/Button";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface FeatureCardProps {
  title: string;
  description: string;
  icon: LucideIcon;
  iconColor: string;
  iconBg: string;
  buttonText: string;
  buttonVariant?: "primary" | "secondary" | "outline" | "emerald" | "slate";
  onClick: () => void;
  loading?: boolean;
  children?: React.ReactNode;
}

export const FeatureCard: React.FC<FeatureCardProps> = ({
  title,
  description,
  icon: Icon,
  iconColor,
  iconBg,
  buttonText,
  buttonVariant = "primary",
  onClick,
  loading = false,
  children,
}) => {
  return (
    <motion.div whileHover={{ scale: 1.02 }} className="feature-card w-full min-w-0">
      <Card className="feature-card__container w-full bg-white rounded-3xl shadow-xl shadow-slate-200/50 border border-slate-100 flex flex-col items-center text-center overflow-hidden">
        <CardHeader className="feature-card__header flex flex-col items-center p-8 pb-0">
          <div className={cn("feature-card__icon-wrapper p-4 rounded-2xl mb-6", iconBg)}>
            <Icon className={cn("feature-card__icon w-10 h-10", iconColor)} />
          </div>
          <CardTitle className="feature-card__title text-2xl font-bold text-slate-900 mb-2">
            {title}
          </CardTitle>
          <CardDescription className="feature-card__description text-slate-500 text-base">
            {description}
          </CardDescription>
        </CardHeader>
        <CardContent className="feature-card__content w-full p-8 pt-6">
          <Button
            variant={buttonVariant}
            size="xl"
            onClick={onClick}
            loading={loading}
            className="feature-card__button w-full"
          >
            {buttonText}
          </Button>
          <div className="feature-card__extra-content w-full mt-4">{children}</div>
        </CardContent>
      </Card>
    </motion.div>
  );
};
