import { CorrelationIdChip } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import type { AuditJourneyGroup } from "@/app/backoffice/audit/_lib/auditJourneyGroups";

/** `HH:MM` of an ISO instant in local time (the session window uses minute precision). */
function localHourMinute(iso: string): string {
  return dateTimeProvider.formatIsoToLocalTimeOfDay(iso).slice(0, 5);
}

/**
 * The Jornada session-group header: the correlation (copyable chip), its local time window
 * `HH:MM`–`HH:MM`, and the entry count — the reconstructed session at a glance, without any session
 * table (D6). Rendered inside the table's `<th scope="rowgroup">`.
 */
export function JourneySessionHeader({ group }: Readonly<{ group: AuditJourneyGroup }>) {
  return (
    <span className="journey-session-header inline-flex flex-wrap items-center gap-2">
      <CorrelationIdChip id={group.correlationId} />
      <span className="text-text-subtle font-mono text-xs tabular-nums">
        {localHourMinute(group.startIso)}–{localHourMinute(group.endIso)}
      </span>
      <span className="text-text-subtle text-xs">
        · {group.count} {group.count === 1 ? "entrada" : "entradas"}
      </span>
    </span>
  );
}
