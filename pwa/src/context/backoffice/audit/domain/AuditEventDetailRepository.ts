import type { AuditEventDetail } from "./AuditChange";

/**
 * Read-only port over the audit event detail read model (`GET /audit/events/{id}`). A deliberate
 * sibling of {@link AuditTimelineRepository}, not a method on it: an audit event is a resource in its
 * own right (SRP/CQRS) — the timeline answers "what happened, in order" (slim, keyset), the detail
 * answers "what exactly changed" (the diff). Backoffice only reads auditoría (D5), so there is no
 * write capability to honour.
 */
export interface AuditEventDetailRepository {
  findById(id: string): Promise<AuditEventDetail>;
}
