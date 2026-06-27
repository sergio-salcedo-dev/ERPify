import { cn } from "@/components/cn";

/**
 * The `[REDACTED]` sentinel for an `ip`/`user_agent` vaccated by GDPR erasure. An inert mono chip:
 * text at AA contrast (`{color.text-muted}`) over `{color.bg-subtle}`, no copy (there is nothing to
 * copy). It means «vaciado a propósito» — semantically distinct from a real null (rendered «—»);
 * the two are never collapsed. The literal `[REDACTED]` is the datum itself, shown verbatim.
 */
interface RedactedValueProps {
  className?: string;
  testId?: string;
}

export function RedactedValue({ className, testId }: Readonly<RedactedValueProps>) {
  return (
    <span
      className={cn(
        "redacted-value bg-bg-subtle text-text-muted inline-flex items-center rounded-sm px-1.5 py-0.5 font-mono text-xs",
        className,
      )}
      data-testid={testId}
    >
      [REDACTED]
    </span>
  );
}
