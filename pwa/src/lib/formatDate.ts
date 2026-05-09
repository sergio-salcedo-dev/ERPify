/**
 * Date formatting and dd/mm/yyyy parsing helpers used across entities (banks,
 * customers, invoices, …). Keep them framework-agnostic — no Next, no React,
 * no Inversify — so any module can import them.
 */

/**
 * Format an ISO 8601 timestamp as `dd/mm/yyyy, HH:mm:ss` in 24-hour time.
 * Falls back to the raw value for non-parseable input so UI tables never
 * show "Invalid Date" if the API ever returns something unexpected.
 */
export function formatDateTime(iso: string): string {
  const ts = Date.parse(iso);
  if (Number.isNaN(ts)) return iso;
  return new Intl.DateTimeFormat("en-GB", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(new Date(ts));
}

/** Parse a `dd/mm/yyyy` string. Returns `null` for empty / malformed input. */
export function parseDdMmYyyy(value: string): { year: number; month: number; day: number } | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(trimmed);
  if (!match) return null;
  const day = Number(match[1]);
  const month = Number(match[2]);
  const year = Number(match[3]);
  if (month < 1 || month > 12 || day < 1 || day > 31) return null;
  // Reject calendar mismatches (e.g. 31/02/2026) by round-tripping through Date.
  const probe = new Date(Date.UTC(year, month - 1, day));
  if (
    probe.getUTCFullYear() !== year ||
    probe.getUTCMonth() !== month - 1 ||
    probe.getUTCDate() !== day
  ) {
    return null;
  }
  return { year, month, day };
}

/** Lower bound (00:00:00 local) for a `dd/mm/yyyy` filter value. */
export function startOfDdMmYyyy(value: string): number | null {
  const parsed = parseDdMmYyyy(value);
  if (!parsed) return null;
  const ts = new Date(parsed.year, parsed.month - 1, parsed.day, 0, 0, 0, 0).getTime();
  return Number.isNaN(ts) ? null : ts;
}

/** Upper bound (23:59:59.999 local) for a `dd/mm/yyyy` filter value. */
export function endOfDdMmYyyy(value: string): number | null {
  const parsed = parseDdMmYyyy(value);
  if (!parsed) return null;
  const ts = new Date(parsed.year, parsed.month - 1, parsed.day, 23, 59, 59, 999).getTime();
  return Number.isNaN(ts) ? null : ts;
}
