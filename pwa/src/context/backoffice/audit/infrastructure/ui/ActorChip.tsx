import { Cog, Eye, EyeOff, KeyRound, User, UserCog } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/components/cn";
import { CopyButton } from "@/components/erpify";
import { ActorType } from "@/context/backoffice/audit/domain/AuditEntry";

const ACTOR_ICON: Readonly<Record<string, LucideIcon>> = {
  [ActorType.User]: User,
  [ActorType.System]: Cog,
  [ActorType.ApiKey]: KeyRound,
  [ActorType.Anonymous]: Eye,
};

/** Actors that never carry an id (so no mono id / copy is rendered for them). */
const ID_LESS_ACTORS: ReadonlySet<string> = new Set([ActorType.System, ActorType.Anonymous]);

function truncateMiddle(value: string): string {
  return value.length > 13 ? `${value.slice(0, 6)}…${value.slice(-4)}` : value;
}

interface ActorChipProps {
  actorType: string;
  actorId: string | null;
  /** Materialised GDPR-erasure state (a row field). When true, the chip reads as anonymized. */
  actorErased?: boolean;
  className?: string;
  testId?: string;
}

/**
 * Renders the actor of an audit row: an icon by `actorType`, the type label, and — when the actor
 * carries one — its id (mono, middle-truncated, copyable in full).
 *
 * The **anonymized** variant (`actorErased`, the materialised GDPR flag) is visually *another thing*,
 * never an id with a marking: a masked icon, the legal-state label «anonimizado (GDPR) · no
 * identificable», and the post-erasure random UUID is **never** shown as an id. `actorErased` is
 * orthogonal to `actorType` (an `anonymous` actor — never identified — is never `actorErased`).
 */
export function ActorChip({
  actorType,
  actorId,
  actorErased = false,
  className,
  testId,
}: Readonly<ActorChipProps>) {
  if (actorErased) {
    return (
      <span
        className={cn(
          "actor-chip actor-chip--anonymized bg-bg-subtle text-text-muted inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs",
          className,
        )}
        data-testid={testId}
        data-anonymized="true"
      >
        <EyeOff className="text-text-subtle size-3.5 flex-none" aria-hidden="true" />
        <span>anonimizado (GDPR)</span>
        <span className="text-text-subtle">· no identificable</span>
      </span>
    );
  }

  const Icon = ACTOR_ICON[actorType] ?? UserCog;
  const showId = actorId !== null && actorId !== "" && !ID_LESS_ACTORS.has(actorType);

  return (
    <span
      className={cn(
        "actor-chip text-foreground inline-flex items-center gap-1.5 text-xs",
        className,
      )}
      data-testid={testId}
    >
      <Icon className="text-text-subtle size-3.5 flex-none" aria-hidden="true" />
      <span className="actor-chip__type">{actorType}</span>
      {showId && actorId ? (
        <span className="actor-chip__id inline-flex items-center gap-1">
          <code className="text-text-muted font-mono" title={actorId}>
            {truncateMiddle(actorId)}
          </code>
          <CopyButton
            value={actorId}
            iconOnly
            size="sm"
            variant="ghost"
            label="Copiar id de actor"
            title="Copiar id de actor"
            testId={testId ? `${testId}-copy` : undefined}
          />
        </span>
      ) : null}
    </span>
  );
}
