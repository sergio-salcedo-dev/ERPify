import { cn } from "@/components/cn";
import { CopyButton } from "@/components/erpify";

/** Serialized-JSON byte budget: past this the block truncates and offers "copy raw" (DESIGN delta). */
const MAX_SERIALIZED_LENGTH = 4096;

interface MetadataBlockProps {
  /** The decoded `metadata` JSON (object), or null/undefined when the record carries none. */
  value: unknown;
  className?: string;
  testId?: string;
}

/**
 * Renders the audit `metadata` JSON. Every key and value is React-escaped text — **never**
 * `dangerouslySetInnerHTML`/`innerHTML` (the field is untrusted input). A size guard truncates a
 * pathological object so it cannot break the drawer layout, while «copy» always yields the full
 * raw JSON. `null` / an empty object reads as «No metadata», not an empty block.
 *
 * By invariant D4 the field carries no sensitive payload; if that ever changes the redaction policy
 * grows in the backend, not here.
 */
export function MetadataBlock({ value, className, testId }: Readonly<MetadataBlockProps>) {
  if (isEmptyMetadata(value)) {
    return (
      <p className={cn("text-text-subtle text-xs", className)} data-testid={testId}>
        No metadata
      </p>
    );
  }

  const raw = safeStringify(value);
  const truncated = raw.length > MAX_SERIALIZED_LENGTH;
  const shown = truncated ? `${raw.slice(0, MAX_SERIALIZED_LENGTH)}\n…` : raw;

  return (
    <div className={cn("metadata-block flex flex-col gap-1", className)} data-testid={testId}>
      <div className="flex items-center justify-end">
        <CopyButton
          value={raw}
          iconOnly
          size="sm"
          variant="ghost"
          label="Copy metadata"
          title="Copy metadata JSON"
          testId={testId ? `${testId}-copy` : undefined}
        />
      </div>
      <pre className="bg-bg-subtle text-foreground overflow-x-auto rounded-md p-2 font-mono text-xs break-words whitespace-pre-wrap">
        {shown}
      </pre>
      {truncated ? (
        <p className="text-text-subtle text-2xs">
          Metadata truncated — use «copy» for the full JSON.
        </p>
      ) : null}
    </div>
  );
}

function isEmptyMetadata(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  return (
    typeof value === "object" &&
    !Array.isArray(value) &&
    Object.keys(value as Record<string, unknown>).length === 0
  );
}

function safeStringify(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2) ?? String(value);
  } catch {
    // A circular / non-serializable object must never crash the drawer.
    return String(value);
  }
}
