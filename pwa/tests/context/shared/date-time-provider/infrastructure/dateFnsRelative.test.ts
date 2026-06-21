import { describe, expect, it } from "vitest";
import { DateFnsDateTimeProvider } from "@/context/shared/date-time-provider/infrastructure/DateFnsDateTimeProvider";

const provider = new DateFnsDateTimeProvider();

describe("DateFnsDateTimeProvider — relative time", () => {
  const base = new Date("2026-06-01T12:00:00.000Z");

  it("formats a past date with an 'ago' suffix relative to a base date", () => {
    const twoDaysEarlier = new Date("2026-05-30T12:00:00.000Z");
    expect(provider.formatToRelative(twoDaysEarlier, base)).toBe("2 days ago");
  });

  it("formats a future date with an 'in' prefix relative to a base date", () => {
    const inThreeHours = new Date("2026-06-01T15:00:00.000Z");
    expect(provider.formatToRelative(inThreeHours, base)).toBe("in about 3 hours");
  });

  it("formatToRelative renders a 5-years distance as 'about 5 years ago' with an explicit base", () => {
    const fiveYearsBefore = new Date("2021-06-01T12:00:00.000Z");
    expect(provider.formatToRelative(fiveYearsBefore, base)).toBe("about 5 years ago");
  });

  it("formatIsoToRelative parses an ISO string and formats it relative to now", () => {
    const wellInThePast = provider.formatToISO(provider.add(provider.now(), -3, "days"));
    expect(provider.formatIsoToRelative(wellInThePast)).toMatch(/ago$/);
  });

  it("formatIsoToRelative returns the raw input back on unparseable values", () => {
    expect(provider.formatIsoToRelative("not-a-date")).toBe("not-a-date");
  });
});
