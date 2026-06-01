import { describe, expect, it } from "vitest";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";

describe("dateTimeProvider singleton — relative time", () => {
  it("exposes formatIsoToRelative and returns a non-empty string for a valid ISO", () => {
    const iso = dateTimeProvider.formatToISO(
      dateTimeProvider.add(dateTimeProvider.now(), -1, "days"),
    );
    const result = dateTimeProvider.formatIsoToRelative(iso);
    expect(result).toMatch(/ago$/);
  });
});
