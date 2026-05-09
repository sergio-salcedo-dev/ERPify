/**
 * `DateTimeProvider` is the domain port for date and time operations.
 *
 * It exposes business-oriented methods (`isWorkDay`, `calculateDuration`,
 * `formatToISO`, …) and intentionally hides every adapter detail: no
 * `date-fns`, no `dayjs`, no `Temporal` types leak across this boundary.
 * The rest of the app must depend on this interface, never on a concrete
 * implementation, so the adapter can be swapped (e.g. native Intl, dayjs,
 * Temporal) without touching domain or application code.
 *
 * Immutability contract: every method that returns a `Date` MUST return a
 * fresh instance — callers can rely on the result not aliasing any input.
 * `Date` instances are never mutated by an adapter.
 */
export interface DateTimeProvider {
  /** Current instant. */
  now(): Date;

  /** Format a date as RFC 3339 / ISO 8601 in UTC, e.g. `2026-04-15T15:30:45.000Z`. */
  formatToISO(date: Date): string;

  /**
   * Format a date for end-user display as `dd/mm/yyyy, HH:mm:ss` in 24-hour
   * time using the `es-ES` locale.
   */
  formatToDisplay(date: Date): string;

  /** Format a date as `dd/mm/yyyy` (no time component). */
  formatToDate(date: Date): string;

  /** Parse an ISO 8601 / RFC 3339 string. Returns `null` for malformed input. */
  parseISO(value: string): Date | null;

  /** Parse a `dd/mm/yyyy` string. Returns `null` for partial / malformed input. */
  parseDdMmYyyy(value: string): Date | null;

  /**
   * Distance from `from` to `to` expressed in `unit`. The result is signed:
   * negative when `to` is before `from`. The result is truncated toward
   * zero (i.e. an integer) for whole units.
   */
  calculateDuration(from: Date, to: Date, unit: DurationUnit): number;

  /** Returns a new date `amount` units after `date`. Negative `amount` subtracts. */
  add(date: Date, amount: number, unit: AddUnit): Date;

  /**
   * Whether `date` is a working day. The default contract treats Saturday
   * and Sunday as non-working days; adapters MAY recognise calendar
   * holidays — see the concrete implementation for the policy in use.
   */
  isWorkDay(date: Date): boolean;

  /** Whether `a` and `b` fall on the same calendar day in the provider's local zone. */
  isSameDay(a: Date, b: Date): boolean;

  /** Whether `a` is strictly before `b`. */
  isBefore(a: Date, b: Date): boolean;

  /** Whether `a` is strictly after `b`. */
  isAfter(a: Date, b: Date): boolean;

  /** Returns a new date at `00:00:00.000` of the same calendar day. */
  startOfDay(date: Date): Date;

  /** Returns a new date at `23:59:59.999` of the same calendar day. */
  endOfDay(date: Date): Date;
}

/** Units supported by {@link DateTimeProvider.calculateDuration}. */
export type DurationUnit = "milliseconds" | "seconds" | "minutes" | "hours" | "days";

/** Units supported by {@link DateTimeProvider.add}. */
export type AddUnit =
  | "milliseconds"
  | "seconds"
  | "minutes"
  | "hours"
  | "days"
  | "businessDays"
  | "weeks"
  | "months"
  | "years";
