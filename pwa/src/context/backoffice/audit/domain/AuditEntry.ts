/**
 * One row of the audit investigation timeline — a read-only projection of an `audit_log` record
 * (the 4.1 read model). Backoffice consumes auditoría, never writes it, so this carries no behaviour.
 *
 * `level` and `actorType` are kept as the raw stored strings (not narrowed unions) on purpose: a
 * forensic read must reflect faithfully whatever the table holds and never break on an unexpected
 * token while investigating — the same stance the API read model takes. The known-value constants
 * below drive presentation (badge variant, actor icon) with a graceful fallback for anything else.
 *
 * `occurredOn` is an ISO-8601 string at microsecond precision (the forensic instant). `actorErased`
 * and `resourceErased` materialise the GDPR-erasure state of each axis (booleans, never derived at
 * read time): an erased subject reads as such from the slim row without lifting the more sensitive
 * `ip`/`userAgent`, which belong to the (deferred) detail read model and are absent here. Both axes
 * are carried because both can be anonymised independently, and an identifier left over from an
 * erasure is a pseudonym: indistinguishable by shape from a live one, so only the flag says which
 * it is, and any affordance that would treat it as a live reference has to consult the flag first.
 */
export interface AuditEntry {
  id: string;
  occurredOn: string;
  level: string;
  action: string;
  actorType: string;
  actorId: string | null;
  correlationId: string;
  resourceType: string | null;
  resourceId: string | null;
  actorErased: boolean;
  resourceErased: boolean;
}

/** Audit classification axis. The stored value may be anything; these are the values we style. */
export const AuditLevel = {
  Activity: "activity",
  Security: "security",
  Change: "change",
} as const;

export type AuditLevel = (typeof AuditLevel)[keyof typeof AuditLevel];

export function isAuditLevel(value: string): value is AuditLevel {
  return (
    value === AuditLevel.Activity || value === AuditLevel.Security || value === AuditLevel.Change
  );
}

/**
 * Actor classification axis — orthogonal to {@link AuditEntry.actorErased}: `anonymous` is "never
 * identified" (a public route), whereas an erased actor was identified and later GDPR-erased. An
 * `anonymous` actor is never `actorErased`.
 */
export const ActorType = {
  Anonymous: "anonymous",
  System: "system",
  ApiKey: "api_key",
  User: "user",
} as const;

export type ActorType = (typeof ActorType)[keyof typeof ActorType];

export function isActorType(value: string): value is ActorType {
  return (
    value === ActorType.Anonymous ||
    value === ActorType.System ||
    value === ActorType.ApiKey ||
    value === ActorType.User
  );
}
