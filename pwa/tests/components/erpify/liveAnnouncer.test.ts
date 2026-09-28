import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { announce } from "@/components/erpify/liveAnnouncer";

const regions = (): NodeListOf<HTMLElement> =>
  document.body.querySelectorAll<HTMLElement>("[data-live-announcer]");

describe("announce", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("speaks through one polite region outside every control", () => {
    announce("ID copied");
    announce("IBAN copied");
    vi.runAllTimers();

    expect(regions()).toHaveLength(1);
    const [region] = regions();
    expect(region).toHaveAttribute("role", "status");
    expect(region).toHaveAttribute("aria-live", "polite");
    expect(region.parentElement).toBe(document.body);
    expect(region).toHaveTextContent("IBAN copied");
  });

  it("empties the region for a beat before an identical message, so it is spoken again", () => {
    announce("Copied");
    vi.runAllTimers();
    expect(regions()[0]).toHaveTextContent("Copied");

    announce("Copied");
    expect(regions()[0].textContent).toBe("");
    vi.runAllTimers();
    expect(regions()[0].textContent).toBe("Copied");
  });

  it("writes text, never markup", () => {
    announce("<img src=x onerror=alert(1)>");
    vi.runAllTimers();
    expect(regions()[0].querySelector("img")).toBeNull();
    expect(regions()[0].textContent).toBe("<img src=x onerror=alert(1)>");
  });
});
