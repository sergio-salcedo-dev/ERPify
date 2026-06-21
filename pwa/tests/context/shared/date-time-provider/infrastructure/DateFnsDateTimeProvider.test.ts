import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { DateTimeProvider } from "@/context/shared/date-time-provider/domain/DateTimeProvider";
import { DateFnsDateTimeProvider } from "@/context/shared/date-time-provider/infrastructure/DateFnsDateTimeProvider";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";

const provider: DateTimeProvider = new DateFnsDateTimeProvider();

describe("DateFnsDateTimeProvider", () => {
  describe("now", () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("returns the current instant", () => {
      const fixed = new Date("2026-04-15T15:30:45.000Z");
      vi.setSystemTime(fixed);
      expect(provider.now().getTime()).toBe(fixed.getTime());
    });
  });

  describe("formatToISO", () => {
    it("returns a valid ISO 8601 / RFC 3339 string for the given instant", () => {
      // The exact suffix depends on the test runner's timezone; assert that
      // round-tripping through `parseISO` yields the same instant.
      const input = new Date("2026-04-15T15:30:45.000Z");
      const out = provider.formatToISO(input);
      expect(out).toMatch(/^2026-04-15T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/);
      const roundTrip = provider.parseISO(out);
      expect(roundTrip?.getTime()).toBe(input.getTime());
    });
  });

  // Builds the expected `dd/MM/yyyy, HH:mm:ss` string from the Date's *local*
  // components, so these assertions hold on any CI host regardless of its TZ
  // while still proving the output reflects the viewer's local timezone.
  const pad = (n: number): string => String(n).padStart(2, "0");
  const localDisplay = (d: Date): string =>
    `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}, ` +
    `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  const localDate = (d: Date): string =>
    `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;

  describe("formatToDisplay", () => {
    it("returns dd/MM/yyyy, HH:mm:ss in 24-hour time", () => {
      const out = provider.formatToDisplay(new Date("2026-04-15T15:30:45Z"));
      expect(out).toMatch(/^\d{2}\/\d{2}\/\d{4},\s\d{2}:\d{2}:\d{2}$/);
      expect(out.toLowerCase()).not.toMatch(/[ap]m/);
    });

    it("renders the UTC instant converted to the viewer's local timezone", () => {
      const instant = new Date("2026-04-15T15:30:45Z");
      expect(provider.formatToDisplay(instant)).toBe(localDisplay(instant));
    });
  });

  describe("formatToDate", () => {
    it("returns dd/MM/yyyy", () => {
      const out = provider.formatToDate(new Date("2026-04-15T00:00:00Z"));
      expect(out).toMatch(/^\d{2}\/\d{2}\/\d{4}$/);
    });

    it("uses the viewer's local calendar day", () => {
      const instant = new Date("2026-04-15T00:00:00Z");
      expect(provider.formatToDate(instant)).toBe(localDate(instant));
    });
  });

  describe("parseISO", () => {
    it("parses a well-formed ISO string", () => {
      const date = provider.parseISO("2026-04-15T15:30:45Z");
      expect(date).not.toBeNull();
      expect(date?.toISOString()).toBe("2026-04-15T15:30:45.000Z");
    });

    it("returns null for empty input", () => {
      expect(provider.parseISO("")).toBeNull();
    });

    it("returns null for malformed input", () => {
      expect(provider.parseISO("not-a-date")).toBeNull();
    });
  });

  describe("parseDdMmYyyy", () => {
    it("parses a well-formed dd/mm/yyyy string", () => {
      const date = provider.parseDdMmYyyy("15/04/2026");
      expect(date).not.toBeNull();
      expect(date?.getFullYear()).toBe(2026);
      expect(date?.getMonth()).toBe(3);
      expect(date?.getDate()).toBe(15);
    });

    it("returns null for partial / unparseable input", () => {
      expect(provider.parseDdMmYyyy("")).toBeNull();
      expect(provider.parseDdMmYyyy("15/04")).toBeNull();
      expect(provider.parseDdMmYyyy("15/04/202")).toBeNull();
      expect(provider.parseDdMmYyyy("2026-04-15")).toBeNull();
    });

    it("rejects calendar mismatches", () => {
      expect(provider.parseDdMmYyyy("31/02/2026")).toBeNull();
      expect(provider.parseDdMmYyyy("29/02/2025")).toBeNull();
      expect(provider.parseDdMmYyyy("29/02/2024")).not.toBeNull();
    });
  });

  describe("calculateDuration", () => {
    const a = new Date("2026-04-15T00:00:00Z");
    const b = new Date("2026-04-18T03:00:00Z"); // +3d 3h

    it("computes positive durations across every unit", () => {
      expect(provider.calculateDuration(a, b, "milliseconds")).toBe(3 * 86_400_000 + 3 * 3_600_000);
      expect(provider.calculateDuration(a, b, "seconds")).toBe(3 * 86_400 + 3 * 3_600);
      expect(provider.calculateDuration(a, b, "minutes")).toBe(3 * 1440 + 3 * 60);
      expect(provider.calculateDuration(a, b, "hours")).toBe(3 * 24 + 3);
      expect(provider.calculateDuration(a, b, "days")).toBe(3);
    });

    it("returns a negative result when `to` is before `from`", () => {
      expect(provider.calculateDuration(b, a, "hours")).toBe(-(3 * 24 + 3));
    });
  });

  describe("add", () => {
    const monday = new Date(2026, 3, 13); // 2026-04-13 is a Monday

    it("adds milliseconds / seconds / minutes / hours", () => {
      expect(provider.add(monday, 500, "milliseconds").getTime() - monday.getTime()).toBe(500);
      expect(provider.add(monday, 30, "seconds").getTime() - monday.getTime()).toBe(30_000);
      expect(provider.add(monday, 15, "minutes").getTime() - monday.getTime()).toBe(900_000);
      expect(provider.add(monday, 2, "hours").getTime() - monday.getTime()).toBe(7_200_000);
    });

    it("adds days / weeks / months / years", () => {
      expect(provider.add(monday, 5, "days").getDate()).toBe(18);
      expect(provider.add(monday, 1, "weeks").getDate()).toBe(20);
      expect(provider.add(monday, 1, "months").getMonth()).toBe(4);
      expect(provider.add(monday, 1, "years").getFullYear()).toBe(2027);
    });

    it("adds business days (skips weekends)", () => {
      // Monday + 5 business days → next Monday.
      const out = provider.add(monday, 5, "businessDays");
      expect(out.getDay()).toBe(1);
      expect(out.getDate()).toBe(20);
    });

    it("returns a fresh date — never mutates the input", () => {
      const input = new Date(monday);
      const before = input.getTime();
      provider.add(input, 1, "days");
      expect(input.getTime()).toBe(before);
    });
  });

  describe("isWorkDay", () => {
    it("returns true for Monday-Friday and false for Saturday-Sunday", () => {
      // 2026-04-13 (Mon) → 2026-04-19 (Sun)
      const days = [13, 14, 15, 16, 17, 18, 19].map((d) => new Date(2026, 3, d));
      expect(days.map((d) => provider.isWorkDay(d))).toEqual([
        true,
        true,
        true,
        true,
        true,
        false,
        false,
      ]);
    });
  });

  describe("isSameDay / isBefore / isAfter", () => {
    const a = new Date("2026-04-15T08:00:00Z");
    const sameDayLater = new Date("2026-04-15T20:00:00Z");
    const nextDay = new Date("2026-04-16T08:00:00Z");

    it("isSameDay is true when both dates fall on the same calendar day", () => {
      expect(provider.isSameDay(a, sameDayLater)).toBe(true);
      expect(provider.isSameDay(a, nextDay)).toBe(false);
    });

    it("isBefore / isAfter use strict inequality", () => {
      expect(provider.isBefore(a, nextDay)).toBe(true);
      expect(provider.isBefore(a, a)).toBe(false);
      expect(provider.isAfter(nextDay, a)).toBe(true);
      expect(provider.isAfter(a, a)).toBe(false);
    });
  });

  describe("startOfDay / endOfDay", () => {
    it("startOfDay returns 00:00:00.000 of the same day", () => {
      const d = new Date(2026, 3, 15, 13, 45, 30);
      const start = provider.startOfDay(d);
      expect(start.getHours()).toBe(0);
      expect(start.getMinutes()).toBe(0);
      expect(start.getSeconds()).toBe(0);
      expect(start.getMilliseconds()).toBe(0);
      expect(start.getDate()).toBe(d.getDate());
    });

    it("endOfDay returns 23:59:59.999 of the same day", () => {
      const d = new Date(2026, 3, 15, 13, 45, 30);
      const end = provider.endOfDay(d);
      expect(end.getHours()).toBe(23);
      expect(end.getMinutes()).toBe(59);
      expect(end.getSeconds()).toBe(59);
      expect(end.getMilliseconds()).toBe(999);
      expect(end.getDate()).toBe(d.getDate());
    });
  });

  describe("formatIsoToLocalDateTime", () => {
    it("formats a well-formed ISO timestamp via formatToDisplay (local timezone)", () => {
      const out = provider.formatIsoToLocalDateTime("2026-04-15T15:30:45Z");
      expect(out).toBe(localDisplay(new Date("2026-04-15T15:30:45Z")));
    });

    it("returns the raw value when the input cannot be parsed", () => {
      expect(provider.formatIsoToLocalDateTime("not-a-date")).toBe("not-a-date");
      expect(provider.formatIsoToLocalDateTime("")).toBe("");
    });
  });

  describe("parseDdMmYyyyToStartTimestamp / parseDdMmYyyyToEndTimestamp", () => {
    it("returns null for unparseable / empty input on both ends", () => {
      expect(provider.parseDdMmYyyyToStartTimestamp("")).toBeNull();
      expect(provider.parseDdMmYyyyToStartTimestamp("15/04")).toBeNull();
      expect(provider.parseDdMmYyyyToStartTimestamp("31/02/2026")).toBeNull();
      expect(provider.parseDdMmYyyyToEndTimestamp("")).toBeNull();
      expect(provider.parseDdMmYyyyToEndTimestamp("nope")).toBeNull();
    });

    it("places start at 00:00:00.000 and end at 23:59:59.999 of the same day", () => {
      const start = provider.parseDdMmYyyyToStartTimestamp("15/04/2026");
      const end = provider.parseDdMmYyyyToEndTimestamp("15/04/2026");
      expect(start).not.toBeNull();
      expect(end).not.toBeNull();
      if (start === null || end === null) return;
      expect(end - start).toBe(86_400_000 - 1);
    });
  });

  describe("parseIsoDateToStartTimestamp / parseIsoDateToEndTimestamp", () => {
    it("returns null for unparseable / empty / partial / impossible input", () => {
      expect(provider.parseIsoDateToStartTimestamp("")).toBeNull();
      expect(provider.parseIsoDateToStartTimestamp("2026-04")).toBeNull();
      expect(provider.parseIsoDateToStartTimestamp("2026-02-31")).toBeNull();
      expect(provider.parseIsoDateToStartTimestamp("15/04/2026")).toBeNull();
      expect(provider.parseIsoDateToEndTimestamp("")).toBeNull();
      expect(provider.parseIsoDateToEndTimestamp("nope")).toBeNull();
    });

    it("places start at 00:00:00.000 and end at 23:59:59.999 of the same day", () => {
      const start = provider.parseIsoDateToStartTimestamp("2026-04-15");
      const end = provider.parseIsoDateToEndTimestamp("2026-04-15");
      expect(start).not.toBeNull();
      expect(end).not.toBeNull();
      if (start === null || end === null) return;
      expect(end - start).toBe(86_400_000 - 1);
    });

    it("agrees with the dd/mm/yyyy variant for the same calendar day", () => {
      expect(provider.parseIsoDateToStartTimestamp("2026-04-15")).toBe(
        provider.parseDdMmYyyyToStartTimestamp("15/04/2026"),
      );
      expect(provider.parseIsoDateToEndTimestamp("2026-04-15")).toBe(
        provider.parseDdMmYyyyToEndTimestamp("15/04/2026"),
      );
    });
  });
});

describe("DateTimeProvider DI index", () => {
  it("exports a singleton instance typed as the domain interface", () => {
    // Smoke test: the export is callable through the interface contract.
    const p: DateTimeProvider = dateTimeProvider;
    const date = p.parseISO("2026-04-15T15:30:45Z");
    expect(date?.toISOString()).toBe("2026-04-15T15:30:45.000Z");
  });
});
