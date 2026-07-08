import { describe, expect, it } from "vitest";
import type { DateTimeProvider } from "@/context/shared/date-time-provider/domain/DateTimeProvider";
import { DateFnsDateTimeProvider } from "@/context/shared/date-time-provider/infrastructure/DateFnsDateTimeProvider";
import { isRecentlyCreated } from "@/app/backoffice/banks/_lib/bankRecency";

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

  it.each([
    { case: "createdAt is older than the window", createdAt: "2026-05-01T12:00:00.000Z" },
    {
      case: "createdAt is in the future (clock skew is not 'new')",
      createdAt: "2026-06-05T12:00:00.000Z",
    },
    { case: "the timestamp is unparseable", createdAt: "not-a-date" },
  ])("is false when $case", ({ createdAt }) => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated(createdAt, provider)).toBe(false);
  });

  it("honours a custom window", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-20T12:00:00.000Z", provider, 30)).toBe(true);
  });
});
