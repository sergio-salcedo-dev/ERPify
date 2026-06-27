import { describe, expect, it } from "vitest";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";

// These two formatters back the audit timeline (per-row time of day) and its day-divider headers.
// Assertions are timezone-invariant: seconds and milliseconds never shift across timezones, so we
// pin those; the calendar day and hour vary by the runner's zone and are not asserted exactly.
describe("dateTimeProvider audit formatters", () => {
  const iso = "2026-06-12T12:04:31.118402+00:00";

  describe("formatIsoToLocalTimeOfDay", () => {
    it("renders HH:mm:ss.SSS, preserving seconds and milliseconds", () => {
      const result = dateTimeProvider.formatIsoToLocalTimeOfDay(iso);
      expect(result).toMatch(/^\d{2}:\d{2}:\d{2}\.\d{3}$/);
      expect(result.endsWith(":31.118")).toBe(true);
    });

    it("returns the raw input when unparseable", () => {
      expect(dateTimeProvider.formatIsoToLocalTimeOfDay("not-a-date")).toBe("not-a-date");
    });
  });

  describe("formatIsoToLongDate", () => {
    it("renders a long local date (es-ES) and never the raw ISO", () => {
      const result = dateTimeProvider.formatIsoToLongDate(iso);
      expect(result).toContain("2026");
      expect(result).toMatch(/de .*2026/); // es-ES "<d> de <month> de 2026"
      expect(result).not.toBe(iso);
    });

    it("returns the raw input when unparseable", () => {
      expect(dateTimeProvider.formatIsoToLongDate("nope")).toBe("nope");
    });
  });
});
