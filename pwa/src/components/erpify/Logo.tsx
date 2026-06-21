import Link from "next/link";
import { ShieldCheck } from "lucide-react";
import { cn } from "@/components/cn";

export type LogoSize = "sm" | "md" | "lg";
export type LogoVariant = "plain" | "badge";

export interface LogoProps {
  /** Destination URL. When omitted, the logo renders as a non-interactive `<span>`. */
  href?: string;
  /** Visual size of the icon and wordmark. */
  size?: LogoSize;
  /** `plain` shows a bare icon; `badge` wraps the icon in a primary-colored square. */
  variant?: LogoVariant;
  /** Hide the "Erpify" wordmark and show the mark only (e.g. compact sidebars). */
  iconOnly?: boolean;
  className?: string;
  iconClassName?: string;
  textClassName?: string;
  testId?: string;
}

const sizeClasses: Record<LogoSize, { icon: string; text: string; badge: string; gap: string }> = {
  sm: { icon: "size-4", text: "text-base", badge: "p-1.5 rounded-md", gap: "gap-1.5" },
  md: { icon: "size-5", text: "text-lg", badge: "p-2 rounded-lg", gap: "gap-2" },
  lg: { icon: "size-6", text: "text-2xl", badge: "p-2 rounded-lg", gap: "gap-2" },
};

export function Logo({
  href,
  size = "md",
  variant = "plain",
  iconOnly = false,
  className,
  iconClassName,
  textClassName,
  testId,
}: Readonly<LogoProps>) {
  const sizes = sizeClasses[size];

  const icon =
    variant === "badge" ? (
      <span
        className={cn("logo__mark bg-primary inline-flex items-center justify-center", sizes.badge)}
        aria-hidden="true"
      >
        <ShieldCheck
          className={cn("logo__icon text-primary-foreground", sizes.icon, iconClassName)}
          aria-hidden="true"
        />
      </span>
    ) : (
      <ShieldCheck
        className={cn("logo__icon text-primary", sizes.icon, iconClassName)}
        aria-hidden="true"
      />
    );

  const wordmark = iconOnly ? null : (
    <span
      className={cn(
        "logo__text text-foreground font-bold tracking-tight",
        sizes.text,
        textClassName,
      )}
    >
      Erpify
    </span>
  );

  const wrapperClass = cn(
    "logo inline-flex items-center hover:opacity-80 transition-opacity",
    sizes.gap,
    className,
  );

  if (href) {
    return (
      <Link
        href={href}
        className={wrapperClass}
        aria-label={iconOnly ? "Erpify" : undefined}
        data-testid={testId}
      >
        {icon}
        {wordmark}
      </Link>
    );
  }

  return (
    <span
      className={wrapperClass}
      aria-label={iconOnly ? "Erpify" : undefined}
      data-testid={testId}
    >
      {icon}
      {wordmark}
    </span>
  );
}
