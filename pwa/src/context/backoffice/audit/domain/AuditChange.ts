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

/** A scalar, a real `null`, or a crypto-shredded sealed value — the three shapes a diff side can take. */
export type AuditFieldValue = AuditScalar | AuditSealedValue | null;

/** One field's before/after pair. Either side may be `null` (added: `old` null; removed: `new` null). */
export interface AuditFieldChange {
  old: AuditFieldValue;
  new: AuditFieldValue;
}

/** The decoded `metadata.changes` map: `{ "<field>": { old, new } }`, the field key forensic-faithful. */
export type AuditChanges = Record<string, AuditFieldChange>;

/**
 * Mirrors the backend's closed set of write kinds (`Erpify\Shared\Audit\Domain\AuditWriteOperation`),
 * carried as `metadata.operation` on a `change` row. Optional: only present once the write path that
 * captured the row started stamping it, so an older row (or one from a write path that skips capture)
 * has no operation and the read side must treat its absence as "unknown", never as a fourth kind.
 */
export const AuditWriteOperation = {
  Created: "CREATED",
  Updated: "UPDATED",
  Deleted: "DELETED",
} as const;

export type AuditWriteOperation = (typeof AuditWriteOperation)[keyof typeof AuditWriteOperation];

/**
 * The full audit event read model behind `GET /audit/events/{id}`: the same slim fields as a timeline
 * {@link AuditEntry} plus the decoded `metadata`. For a `change` row `metadata.changes` carries the
 * field-by-field diff and `metadata.operation` the write kind that produced it; the rest of `metadata`
 * stays an open record (forensic fidelity — an unknown key still reaches the UI). `ip`/`user_agent` are
 * deliberately absent: the E1 payload is diff-only.
 */
export interface AuditEventDetail extends AuditEntry {
  metadata: { changes?: AuditChanges; operation?: AuditWriteOperation } & Record<string, unknown>;
}

/** The four shapes a field change can take, derived from which side is `null`. */
export const ChangeKind = {
  Added: "added",
  Removed: "removed",
  Changed: "changed",
  Empty: "empty",
} as const;

export type ChangeKind = (typeof ChangeKind)[keyof typeof ChangeKind];

/**
 * Classifies a field change by which side is present: a `null` `old` reads as **added**, a `null`
 * `new` as **removed**, both sides present as **changed**, and both sides `null` as **empty** — a
 * field carried by the record that never held a value. This is the non-colour signal the diff
 * renders as text/marker; colour only reinforces it.
 *
 * The function is total over the 2×2 of (null, non-null) sides on purpose: an empty field is a
 * classification, not a precondition violation, so no caller has to guard the input to make the
 * result meaningful.
 */
export function changeKind(change: AuditFieldChange): ChangeKind {
  if (isNoOpChange(change)) return ChangeKind.Empty;
  if (change.old === null) return ChangeKind.Added;
  if (change.new === null) return ChangeKind.Removed;
  return ChangeKind.Changed;
}

/**
 * A field whose `old` and `new` are both `null`: present in the record, never populated. It carries
 * no *transition*, but its presence is itself evidence on a forensic trail — "this optional field
 * was empty at this instant" is a fact a reader may need, and it is recoverable nowhere else in the
 * UI, so the diff renders it in a neutral state rather than dropping it.
 */
export function isNoOpChange(change: AuditFieldChange): boolean {
  return change.old === null && change.new === null;
}
