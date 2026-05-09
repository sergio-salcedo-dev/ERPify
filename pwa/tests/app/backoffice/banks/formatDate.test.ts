import { describe, expect, it } from "vitest";
import {
  endOfDdMmYyyy,
  formatBankDateTime,
  parseDdMmYyyy,
  startOfDdMmYyyy,
} from "@/app/backoffice/banks/_lib/formatDate";

describe("formatBankDateTime", () => {
  it("renders dd/mm/yyyy, HH:mm:ss in 24-hour time", () => {
    // 15:30 in UTC → en-GB locale displays the same time string when the
    // test environment uses UTC, which is the case for vitest in CI.
    const out = formatBankDateTime("2026-04-15T15:30:45Z");
    // Match `dd/mm/yyyy, HH:mm:ss` (no AM/PM marker anywhere in the string).
    expect(out).toMatch(/^\d{2}\/\d{2}\/\d{4},\s\d{2}:\d{2}:\d{2}$/);
    expect(out.toLowerCase()).not.toMatch(/[ap]m/);
  });

  it("falls back to the raw value for unparseable input", () => {
    expect(formatBankDateTime("not-a-date")).toBe("not-a-date");
  });
});

describe("parseDdMmYyyy", () => {
  it("parses well-formed dd/mm/yyyy", () => {
    expect(parseDdMmYyyy("15/04/2026")).toEqual({ year: 2026, month: 4, day: 15 });
  });

  it("returns null for empty or whitespace input", () => {
    expect(parseDdMmYyyy("")).toBeNull();
    expect(parseDdMmYyyy("   ")).toBeNull();
  });

  it("rejects partial values still being typed", () => {
    expect(parseDdMmYyyy("15")).toBeNull();
    expect(parseDdMmYyyy("15/04")).toBeNull();
    expect(parseDdMmYyyy("15/04/202")).toBeNull();
  });

  it("rejects out-of-range months and days", () => {
    expect(parseDdMmYyyy("15/13/2026")).toBeNull();
    expect(parseDdMmYyyy("32/01/2026")).toBeNull();
  });

  it("rejects calendar mismatches", () => {
    expect(parseDdMmYyyy("31/02/2026")).toBeNull();
    expect(parseDdMmYyyy("29/02/2025")).toBeNull(); // 2025 is not a leap year
    expect(parseDdMmYyyy("29/02/2024")).toEqual({ year: 2024, month: 2, day: 29 });
  });

  it("rejects iso (yyyy-mm-dd) input", () => {
    expect(parseDdMmYyyy("2026-04-15")).toBeNull();
  });
});

describe("startOfDdMmYyyy / endOfDdMmYyyy", () => {
  it("returns null for unparseable input", () => {
    expect(startOfDdMmYyyy("")).toBeNull();
    expect(endOfDdMmYyyy("nope")).toBeNull();
  });

  it("places start at 00:00:00.000 and end at 23:59:59.999 of the same day", () => {
    const start = startOfDdMmYyyy("15/04/2026");
    const end = endOfDdMmYyyy("15/04/2026");
    expect(start).not.toBeNull();
    expect(end).not.toBeNull();
    if (start === null || end === null) return;
    expect(end - start).toBe(86_400_000 - 1);
  });
});
