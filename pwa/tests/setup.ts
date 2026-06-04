import "@testing-library/jest-dom";
import "reflect-metadata";

// Defensive shim for contributors on Node versions outside the supported
// engines range (see .nvmrc — Node 24). On Node 25+, `globalThis.localStorage`
// exists as a stub `{}` with no Storage methods. Vitest's jsdom env populates
// `globalThis` only with keys NOT already present, so it sees the stub and
// skips copying jsdom's real Storage — tests then crash with "getItem is not
// a function". Re-expose jsdom's real Storage to unblock those devs.
//
// On Node 20/22/24 (CI + .nvmrc): no stub, vitest copies jsdom's storage as
// usual; this block resolves to the same Storage instance, so it's a no-op.
// jsdom ships no ResizeObserver; `useIsTruncated` (tooltip-if-truncated)
// observes cell spans with it. A no-op stub keeps components mountable —
// tests that need a truncated state mock the hook or set scroll/client
// dimensions explicitly.
class ResizeObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver;

type WithJSDOM = typeof globalThis & { jsdom?: { window: Window } };
const dom = (globalThis as WithJSDOM).jsdom;
if (dom?.window) {
  Object.defineProperty(globalThis, "localStorage", {
    configurable: true,
    get: () => dom.window.localStorage,
  });
  Object.defineProperty(globalThis, "sessionStorage", {
    configurable: true,
    get: () => dom.window.sessionStorage,
  });
}
