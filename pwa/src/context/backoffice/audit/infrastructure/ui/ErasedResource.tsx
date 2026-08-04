import { EyeOff } from "lucide-react";
import { cn } from "@/components/cn";

interface ErasedResourceProps {
  resourceType: string | null;
  className?: string;
}

/**
 * The resource axis of a row whose subject has been erased: the type it still denotes, and the legal
 * state in place of an id.
 *
 * Once the axis is erased its `resourceId` is an anonymisation pseudonym — a fresh UUID with nothing
 * behind it — so showing it as an id, or letting it be copied, hands out an identifier that denotes
 * nobody while looking exactly like one that does. {@link ActorChip} takes the same decision for the
 * actor axis; this is that contract on the other column, and it lives in one place so the two cannot
 * drift apart.
 */
export function ErasedResource({ resourceType, className }: Readonly<ErasedResourceProps>) {
  return (
    <span
      className={cn("text-text-muted inline-flex items-center gap-1.5", className)}
      data-anonymized="true"
    >
      <EyeOff className="text-text-subtle size-3.5 flex-none" aria-hidden="true" />
      {resourceType ? <span>{resourceType}</span> : null}
      {resourceType ? <span className="text-text-subtle">·</span> : null}
      <span>anonimizado (GDPR)</span>
    </span>
  );
}
