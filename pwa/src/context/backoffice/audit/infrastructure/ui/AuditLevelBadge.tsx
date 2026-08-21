import { cva } from "class-variance-authority";
import { cn } from "@/components/cn";
import { AuditLevel } from "@/context/backoffice/audit/domain/AuditEntry";

/**
 * The audit classification badge. Dot-first like `<StatusBadge>` (the label is always neutral text;
 * the hue lives only in the 6px dot, so colour is never the sole channel), but the dot's two values
 * are audit-specific: `activity` rides the neutral subtle grey (high-volume baseline, calm read),
 * `security` rides `{color.security}` — a class of auditoría, NOT a severity, so never `{color.danger}`.
 *
 * Built here in the audit context (not promoted to `@/components/erpify`) because it consumes the
 * audit `level` vocabulary; the matching row also draws a 2px lateral accent for `security`/`change`
 * (a second channel). `change` rides `{color.brand}` — a write event, distinct from `security` and
 * never `{color.danger}`. `level` is the raw stored string: an unknown token still renders, dot
 * neutral, label verbatim — a forensic read never breaks on an unexpected value.
 *
 * A `<span>`, for the reason `<StatusBadge>` records: `<output>` carries role `status`, and a
 * timeline page renders one of these per row — every one of them a live region announcing
 * nothing. Gated by `tests/live-region-surfaces.test.ts`.
 */
const dotVariants = cva("size-1.5 flex-none rounded-full", {
  variants: {
    tone: {
      activity: "bg-text-subtle",
      security: "bg-security",
      change: "bg-brand",
    },
  },
  defaultVariants: { tone: "activity" },
});

const KNOWN_LABEL: Readonly<Record<string, string>> = {
  [AuditLevel.Activity]: "Activity",
  [AuditLevel.Security]: "Security",
  [AuditLevel.Change]: "Cambio",
};

function toneFor(level: string): "activity" | "security" | "change" {
  if (level === AuditLevel.Security) return "security";
  if (level === AuditLevel.Change) return "change";
  return "activity";
}

interface AuditLevelBadgeProps {
  level: string;
  className?: string;
  testId?: string;
}

export function AuditLevelBadge({ level, className, testId }: Readonly<AuditLevelBadgeProps>) {
  const tone = toneFor(level);
  const label = KNOWN_LABEL[level] ?? level;
  return (
    <span
      className={cn(
        "audit-level-badge bg-muted text-muted-foreground inline-flex h-5 items-center gap-1.5 rounded-full px-2 text-2xs font-medium",
        className,
      )}
      data-testid={testId}
      data-level={level}
    >
      <span className={dotVariants({ tone })} aria-hidden="true" />
      {label}
    </span>
  );
}
