import "@testing-library/jest-dom";
import "reflect-metadata";

// Node 22+ ships a stub `globalThis.localStorage` ({}) which causes vitest's
// jsdom environment to skip copying jsdom's real Storage (its filter excludes
// keys already present on global). Re-expose jsdom's real storage.
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
