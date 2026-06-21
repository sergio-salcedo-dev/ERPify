import type { ReactNode } from "react";
import { Lock, Search, Sparkles, type LucideIcon } from "lucide-react";
import { cn } from "@/context/shared/styling/infrastructure/classNames";

type EmptyStateVariant = "first-run" | "filtered-to-zero" | "permission-denied";

interface EmptyStateProps {
  variant: EmptyStateVariant;
  heading: string;
  description: string;
  /** Optional recovery action (e.g. "Create your first invoice", "Clear filters"). */
  action?: ReactNode;
  /** Override the default per-variant icon (e.g. a feature-specific placeholder). */
  icon?: LucideIcon;
  className?: string;
}

const iconByVariant: Record<EmptyStateVariant, LucideIcon> = {
  "first-run": Sparkles,
  "filtered-to-zero": Search,
  "permission-denied": Lock,
};

export function EmptyState({
  variant,
  heading,
  description,
  action,
  icon,
  className,
}: Readonly<EmptyStateProps>) {
  const Icon = icon ?? iconByVariant[variant];
  return (
    <section
      data-empty-variant={variant}
      className={cn(
        "border-border bg-card/40 flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-8 text-center",
        className,
      )}
    >
      <Icon className="text-muted-foreground size-6" aria-hidden="true" />
      <h3 className="text-foreground text-lg font-semibold">{heading}</h3>
      <p className="text-muted-foreground max-w-prose text-sm">{description}</p>
      {action ? <div className="mt-2">{action}</div> : null}
    </section>
  );
}
