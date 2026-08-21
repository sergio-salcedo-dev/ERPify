import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  hardNavigate,
  NAVIGATION_COMMIT_BUDGET_MS,
} from "@/context/shared/navigation/infrastructure/hardNavigate";

const DESTINATION = "/somewhere";

describe("hardNavigate", () => {
  let replace: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    replace = vi.fn();
    // jsdom's `location` is unforgeable — its own methods are neither writable nor
    // configurable — so the global is replaced wholesale.
    vi.stubGlobal("location", { pathname: "/here", search: "", replace });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("reports a refusal synchronously, because a throw is already the whole answer", () => {
    replace.mockImplementation(() => {
      throw new Error("refused");
    });
    const onFailure = vi.fn();

    hardNavigate(DESTINATION, onFailure);

    expect(onFailure).toHaveBeenCalledWith("refused");
    // Nothing is pending afterwards: the navigation was never scheduled, so there is nothing
    // for a budget to be the deadline of.
    vi.advanceTimersByTime(NAVIGATION_COMMIT_BUDGET_MS * 2);
    expect(onFailure).toHaveBeenCalledTimes(1);
  });

  it("reports a navigation that neither raised nor committed", () => {
    const onFailure = vi.fn();

    hardNavigate(DESTINATION, onFailure);

    expect(replace).toHaveBeenCalledWith(DESTINATION);
    // This is the outcome a try/catch structurally cannot see: a sandboxed navigable IGNORES
    // the navigation, so nothing throws and nothing unloads. Every caller that latched state
    // across the call stayed latched for the life of the document.
    expect(onFailure).not.toHaveBeenCalled();

    vi.advanceTimersByTime(NAVIGATION_COMMIT_BUDGET_MS);

    expect(onFailure).toHaveBeenCalledWith("not-committed");
  });

  it("refuses a destination that is not a root-relative in-app path", () => {
    const onFailure = vi.fn();

    hardNavigate("https://evil.com/", onFailure);

    // This is the single sanctioned hard-navigation sink in src/, and therefore the one line
    // the navigation linter will never look at again. Reported through `refused` rather than
    // thrown, so a caller that latched state still releases it.
    expect(replace).not.toHaveBeenCalled();
    expect(onFailure).toHaveBeenCalledWith("refused");
  });

  it.each([
    ["a host smuggled behind a stripped TAB", "/\t/evil.com"],
    ["a protocol-relative host", "//evil.com"],
    ["a scheme-bearing destination", "javascript:alert(1)"],
  ])("refuses %s", (_label, url) => {
    const onFailure = vi.fn();

    hardNavigate(url, onFailure);

    expect(replace).not.toHaveBeenCalled();
    expect(onFailure).toHaveBeenCalledWith("refused");
  });

  it("stays silent once the document is discarded", () => {
    const onFailure = vi.fn();

    hardNavigate(DESTINATION, onFailure);
    globalThis.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: false }));
    vi.advanceTimersByTime(NAVIGATION_COMMIT_BUDGET_MS * 2);

    // `pagehide` is the commit signal read by elimination: a document that committed never
    // observes its own budget elapsing. Without it the budget would fire on every successful
    // navigation slower than itself, and each caller would act on a failure that did not happen.
    expect(onFailure).not.toHaveBeenCalled();
  });

  it("keeps the budget armed when the document is only cached, not discarded", () => {
    const onFailure = vi.fn();

    hardNavigate(DESTINATION, onFailure);
    // `pagehide` states two different facts. `persisted: true` is bfcache entry or a freeze —
    // iOS Safari fires it whenever the app is backgrounded — and the document can come back.
    // Reading that as "committed" disarmed the only bound a caller has, permanently, on an
    // ordinary phone interaction: the claim it releases would then never be released and the
    // application stays blanked with no route away.
    globalThis.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: true }));
    vi.advanceTimersByTime(NAVIGATION_COMMIT_BUDGET_MS);

    expect(onFailure).toHaveBeenCalledWith("not-committed");
  });

  it("honours a caller's own budget", () => {
    const onFailure = vi.fn();

    hardNavigate(DESTINATION, onFailure, 50);

    vi.advanceTimersByTime(49);
    expect(onFailure).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(onFailure).toHaveBeenCalledWith("not-committed");
  });
});
