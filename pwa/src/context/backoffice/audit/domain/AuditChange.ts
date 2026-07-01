import type { AuditEntry } from "./AuditEntry";

/**
 * The scalar leaf of an audit diff. The backend scalarises every changed property before it reaches
 * the wire (enums, dates → strings; counts → numbers; flags → booleans), so the read side never has
 * to interpret a structured value — it renders the scalar verbatim. `null` is a first-class value
 * (a field set to / cleared to nothing), distinct from an absent field.
 */
export type AuditScalar = string | number | boolean;

/**
 * A personal-data value the backend crypto-shredded: its plaintext is sealed under a per-subject key and
 * only the ciphertext travels. The read side never decrypts it (that is a privileged, post-auth concern);
 * it renders a "sealed" sentinel, so the marker exists purely to distinguish a sealed value from a clear
 * one — the ciphertext itself is never shown.
 */
export interface AuditSealedValue {
  __enc__: string;
}

export function isAuditSealedValue(value: unknown): value is AuditSealedValue {
  return (
    typeof value === "object" &&
    value !== null &&
    "__enc__" in value &&
    typeof (value as { __enc__: unknown }).__enc__ === "string"
  );
}

/** One field's before/after pair. Either side may be `null` (added: `old` null; removed: `new` null). */
export interface AuditFieldChange {
  old: AuditScalar | AuditSealedValue | null;
  new: AuditScalar | AuditSealedValue | null;
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
