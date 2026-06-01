import { cva, type VariantProps } from "class-variance-authority";
import { AlertTriangle, CheckCircle2, Circle, Info, XCircle, type LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

type StatusVariant = "success" | "warning" | "danger" | "info" | "neutral";

const statusVariants = cva(
  "inline-flex h-5 items-center gap-1 rounded-full border px-2 text-xs font-medium",
  {
    variants: {
      variant: {
        success: "border-transparent bg-success/15 text-success",
        warning: "border-transparent bg-warning/15 text-warning",
        danger: "bg-destructive/15 text-destructive border-transparent",
        info: "bg-primary/15 text-primary border-transparent",
        neutral: "border-border text-muted-foreground bg-transparent",
      },
    },
    defaultVariants: {
      variant: "neutral",
    },
  },
);

const iconByVariant: Record<StatusVariant, LucideIcon> = {
  success: CheckCircle2,
  warning: AlertTriangle,
  danger: XCircle,
  info: Info,
  neutral: Circle,
};

interface StatusBadgeProps extends VariantProps<typeof statusVariants> {
  variant: StatusVariant;
  label: string;
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

export function StatusBadge({ variant, label, className, testId }: Readonly<StatusBadgeProps>) {
  const Icon = iconByVariant[variant];
  return (
    <output className={cn(statusVariants({ variant }), className)} data-testid={testId}>
      <Icon className="size-3" aria-hidden="true" />
      {label}
    </output>
  );
}
