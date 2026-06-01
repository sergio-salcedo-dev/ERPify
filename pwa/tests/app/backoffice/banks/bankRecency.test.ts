import { describe, expect, it } from "vitest";
import type { DateTimeProvider } from "@/context/shared/domain/DateTimeProvider/DateTimeProvider";
import { DateFnsDateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider";
import { countRecentlyCreated, isRecentlyCreated } from "@/app/backoffice/banks/_lib/bankRecency";

// A provider whose "now" is pinned, so the test is deterministic.
function providerWithNow(nowIso: string): DateTimeProvider {
  const base = new DateFnsDateTimeProvider();
  return Object.assign(Object.create(Object.getPrototypeOf(base)), base, {
    now: () => new Date(nowIso),
  });
}

const NOW = "2026-06-01T12:00:00.000Z";

describe("isRecentlyCreated", () => {
  it("is true when createdAt is within the window (default 7 days)", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-28T12:00:00.000Z", provider)).toBe(true);
  });

  it("is false when createdAt is older than the window", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-01T12:00:00.000Z", provider)).toBe(false);
  });

  it("is false for a future createdAt (clock skew is not 'new')", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-06-05T12:00:00.000Z", provider)).toBe(false);
  });

  it("is false for an unparseable timestamp", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("not-a-date", provider)).toBe(false);
  });

  it("honours a custom window", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-20T12:00:00.000Z", provider, 30)).toBe(true);
  });
});

describe("countRecentlyCreated", () => {
  it("counts only the timestamps within the window", () => {
    const provider = providerWithNow(NOW);
    const isos = [
      "2026-05-29T12:00:00.000Z", // within 7d
      "2026-05-31T12:00:00.000Z", // within 7d
      "2026-04-01T12:00:00.000Z", // older
      "not-a-date", // unparseable
    ];
    expect(countRecentlyCreated(isos, provider)).toBe(2);
  });
});
