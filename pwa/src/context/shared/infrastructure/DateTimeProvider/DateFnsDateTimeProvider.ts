import {
  addBusinessDays,
  addDays,
  addHours,
  addMilliseconds,
  addMinutes,
  addMonths,
  addSeconds,
  addWeeks,
  addYears,
  differenceInDays,
  differenceInHours,
  differenceInMilliseconds,
  differenceInMinutes,
  differenceInSeconds,
  endOfDay,
  format as dfFormat,
  formatISO,
  isAfter,
  isBefore,
  isSameDay,
  isValid,
  parse as dfParse,
  parseISO,
  startOfDay,
} from "date-fns";
import type {
  AddUnit,
  DateTimeProvider,
  DurationUnit,
} from "../../domain/DateTimeProvider/DateTimeProvider";

/**
 * `date-fns`-backed adapter for {@link DateTimeProvider}.
 *
 * - Display formatting uses the `es-ES` locale and 24-hour time, rendered in
 *   the viewer's own local timezone (a UTC instant from the API is shown as
 *   Madrid time for a user in Spain, London time for a user in the UK, …).
 * - Working-day policy is Mon-Fri. Calendar holidays are NOT applied here;
 *   subclass or wrap this provider if a calendar-aware policy is required.
 * - All methods that return a `Date` return a fresh instance; inputs are
 *   never mutated.
 */
export class DateFnsDateTimeProvider implements DateTimeProvider {
  private static readonly DISPLAY_LOCALE = "es-ES";

  // No `timeZone` option: `Intl.DateTimeFormat` then renders in the runtime's
  // local zone — i.e. each end user sees timestamps in their own timezone,
  // converted from the UTC instant the backend serves.
  private static readonly DISPLAY_DATE_TIME_FORMATTER = new Intl.DateTimeFormat(
    DateFnsDateTimeProvider.DISPLAY_LOCALE,
    {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    },
  );

  private static readonly DISPLAY_DATE_FORMATTER = new Intl.DateTimeFormat(
    DateFnsDateTimeProvider.DISPLAY_LOCALE,
    {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    },
  );

  private static readonly DD_MM_YYYY_PATTERN = /^(\d{2})\/(\d{2})\/(\d{4})$/;
  private static readonly ISO_YYYY_MM_DD_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

  public now(): Date {
    return new Date();
  }

  public formatToISO(date: Date): string {
    return formatISO(date);
  }

  public formatToDisplay(date: Date): string {
    const p = DateFnsDateTimeProvider.toParts(
      DateFnsDateTimeProvider.DISPLAY_DATE_TIME_FORMATTER,
      date,
    );
    return `${p.day}/${p.month}/${p.year}, ${p.hour}:${p.minute}:${p.second}`;
  }

  public formatToDate(date: Date): string {
    const p = DateFnsDateTimeProvider.toParts(DateFnsDateTimeProvider.DISPLAY_DATE_FORMATTER, date);
    return `${p.day}/${p.month}/${p.year}`;
  }

  /**
   * Reduce an `Intl.DateTimeFormat` part list to a `type → value` map so the
   * output string can be assembled deterministically, independent of the
   * locale's literal separators.
   */
  private static toParts(formatter: Intl.DateTimeFormat, date: Date): Record<string, string> {
    const parts: Record<string, string> = {};
    for (const part of formatter.formatToParts(date)) {
      parts[part.type] = part.value;
    }
    return parts;
  }

  public parseISO(value: string): Date | null {
    if (!value) return null;
    const parsed = parseISO(value);
    return isValid(parsed) ? parsed : null;
  }

  public parseDdMmYyyy(value: string): Date | null {
    const trimmed = value.trim();
    const match = DateFnsDateTimeProvider.DD_MM_YYYY_PATTERN.exec(trimmed);
    if (!match) return null;
    // `parse` accepts impossible dates like 31/02; round-trip via `format` to
    // reject them and align with the strictness expected by the domain port.
    const parsed = dfParse(trimmed, "dd/MM/yyyy", new Date());
    if (!isValid(parsed)) return null;
    const reformatted = dfFormat(parsed, "dd/MM/yyyy");
    return reformatted === trimmed ? parsed : null;
  }

  public calculateDuration(from: Date, to: Date, unit: DurationUnit): number {
    switch (unit) {
      case "milliseconds":
        return differenceInMilliseconds(to, from);
      case "seconds":
        return differenceInSeconds(to, from);
      case "minutes":
        return differenceInMinutes(to, from);
      case "hours":
        return differenceInHours(to, from);
      case "days":
        return differenceInDays(to, from);
    }
  }

  public add(date: Date, amount: number, unit: AddUnit): Date {
    switch (unit) {
      case "milliseconds":
        return addMilliseconds(date, amount);
      case "seconds":
        return addSeconds(date, amount);
      case "minutes":
        return addMinutes(date, amount);
      case "hours":
        return addHours(date, amount);
      case "days":
        return addDays(date, amount);
      case "businessDays":
        return addBusinessDays(date, amount);
      case "weeks":
        return addWeeks(date, amount);
      case "months":
        return addMonths(date, amount);
      case "years":
        return addYears(date, amount);
    }
  }

  public isWorkDay(date: Date): boolean {
    const day = date.getDay();
    return day !== 0 && day !== 6;
  }

  public isSameDay(a: Date, b: Date): boolean {
    return isSameDay(a, b);
  }

  public isBefore(a: Date, b: Date): boolean {
    return isBefore(a, b);
  }

  public isAfter(a: Date, b: Date): boolean {
    return isAfter(a, b);
  }

  public startOfDay(date: Date): Date {
    return startOfDay(date);
  }

  public endOfDay(date: Date): Date {
    return endOfDay(date);
  }

  public formatIsoToDisplay(iso: string): string {
    const date = this.parseISO(iso);
    return date ? this.formatToDisplay(date) : iso;
  }

  public parseDdMmYyyyToStartTimestamp(value: string): number | null {
    const date = this.parseDdMmYyyy(value);
    if (!date) return null;
    const ts = this.startOfDay(date).getTime();
    return Number.isNaN(ts) ? null : ts;
  }

  public parseDdMmYyyyToEndTimestamp(value: string): number | null {
    const date = this.parseDdMmYyyy(value);
    if (!date) return null;
    const ts = this.endOfDay(date).getTime();
    return Number.isNaN(ts) ? null : ts;
  }

  public parseIsoDateToStartTimestamp(value: string): number | null {
    const date = this.parseIsoDate(value);
    if (!date) return null;
    const ts = this.startOfDay(date).getTime();
    return Number.isNaN(ts) ? null : ts;
  }

  public parseIsoDateToEndTimestamp(value: string): number | null {
    const date = this.parseIsoDate(value);
    if (!date) return null;
    const ts = this.endOfDay(date).getTime();
    return Number.isNaN(ts) ? null : ts;
  }

  private parseIsoDate(value: string): Date | null {
    const trimmed = value.trim();
    if (!DateFnsDateTimeProvider.ISO_YYYY_MM_DD_PATTERN.test(trimmed)) return null;
    const parsed = dfParse(trimmed, "yyyy-MM-dd", new Date());
    if (!isValid(parsed)) return null;
    // Reject impossible calendar dates (`2026-02-31`) by round-tripping.
    const reformatted = dfFormat(parsed, "yyyy-MM-dd");
    return reformatted === trimmed ? parsed : null;
  }
}
