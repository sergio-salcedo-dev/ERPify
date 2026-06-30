import type { AuditEntry } from "./AuditEntry";

/**
 * The scalar leaf of an audit diff. The backend scalarises every changed property before it reaches
 * the wire (enums, dates → strings; counts → numbers; flags → booleans), so the read side never has
 * to interpret a structured value — it renders the scalar verbatim. `null` is a first-class value
 * (a field set to / cleared to nothing), distinct from an absent field.
 */
export type AuditScalar = string | number | boolean;

/** One field's before/after pair. Either side may be `null` (added: `old` null; removed: `new` null). */
export interface AuditFieldChange {
  old: AuditScalar | null;
  new: AuditScalar | null;
}

/** The decoded `metadata.changes` map: `{ "<field>": { old, new } }`, the field key forensic-faithful. */
export type AuditChanges = Record<string, AuditFieldChange>;

/**
 * The full audit event read model behind `GET /audit/events/{id}`: the same slim fields as a timeline
 * {@link AuditEntry} plus the decoded `metadata`. For a `change` row `metadata.changes` carries the
 * field-by-field diff; the rest of `metadata` stays an open record (forensic fidelity — an unknown
 * key still reaches the UI). `ip`/`user_agent` are deliberately absent: the E1 payload is diff-only.
 */
export interface AuditEventDetail extends AuditEntry {
  metadata: { changes?: AuditChanges } & Record<string, unknown>;
}

/** The three shapes a field change can take, derived from which side is `null`. */
export const ChangeKind = {
  Added: "added",
  Removed: "removed",
  Changed: "changed",
} as const;

export type ChangeKind = (typeof ChangeKind)[keyof typeof ChangeKind];

/**
 * Classifies a field change by which side is present: a `null` `old` reads as **added**, a `null`
 * `new` as **removed**, anything else (both present, or the degenerate both-null) as **changed**.
 * This is the non-colour signal the diff renders as text/marker — colour only reinforces it.
 */
export function changeKind(change: AuditFieldChange): ChangeKind {
  if (change.old === null && change.new !== null) return ChangeKind.Added;
  if (change.new === null && change.old !== null) return ChangeKind.Removed;
  return ChangeKind.Changed;
}
