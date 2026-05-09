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
import { es } from "date-fns/locale";
import type {
  AddUnit,
  DateTimeProvider,
  DurationUnit,
} from "../../domain/DateTimeProvider/DateTimeProvider";

/**
 * `date-fns`-backed adapter for {@link DateTimeProvider}.
 *
 * - Display formatting uses the `es-ES` locale and 24-hour time.
 * - Working-day policy is Mon-Fri. Calendar holidays are NOT applied here;
 *   subclass or wrap this provider if a calendar-aware policy is required.
 * - All methods that return a `Date` return a fresh instance; inputs are
 *   never mutated.
 */
export class DateFnsDateTimeProvider implements DateTimeProvider {
  private static readonly DISPLAY_DATE_TIME_FORMAT = "dd/MM/yyyy, HH:mm:ss";
  private static readonly DISPLAY_DATE_FORMAT = "dd/MM/yyyy";
  private static readonly DD_MM_YYYY_PATTERN = /^(\d{2})\/(\d{2})\/(\d{4})$/;
  private static readonly ISO_YYYY_MM_DD_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

  public now(): Date {
    return new Date();
  }

  public formatToISO(date: Date): string {
    return formatISO(date);
  }

  public formatToDisplay(date: Date): string {
    return dfFormat(date, DateFnsDateTimeProvider.DISPLAY_DATE_TIME_FORMAT, { locale: es });
  }

  public formatToDate(date: Date): string {
    return dfFormat(date, DateFnsDateTimeProvider.DISPLAY_DATE_FORMAT, { locale: es });
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
