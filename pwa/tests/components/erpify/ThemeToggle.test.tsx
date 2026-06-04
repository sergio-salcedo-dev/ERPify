import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { ThemeProvider } from "next-themes";
import { ThemeToggle } from "@/components/erpify/ThemeToggle";
import { Theme, THEME_STORAGE_KEY } from "@/context/shared/domain/types/theme";

const TEST_ID = "theme-toggle-test";

const NEXT_ACTION_LABEL: Record<Theme, string> = {
  [Theme.LIGHT]: "Switch to dark theme",
  [Theme.DARK]: "Switch to system theme",
  [Theme.SYSTEM]: "Switch to light theme",
};

/**
 * jsdom ships no `matchMedia`. next-themes calls it to resolve the `system`
 * preference, so install a controllable stub: `setOsDark(...)` flips what the
 * `(prefers-color-scheme: dark)` query reports.
 */
function installMatchMedia(): (osDark: boolean) => void {
  let osDark = false;
  // Store the wide `EventListener` type so it stays assignable to the real
  // MediaQueryList.addEventListener signature — no cast needed on add/delete.
  // A MediaQueryListEvent is an Event, so the dispatch loop below invokes each
  // listener with a structurally-valid argument.
  const listeners = new Set<EventListener>();

  const mql: MediaQueryList = {
    get matches() {
      return osDark;
    },
    media: "(prefers-color-scheme: dark)",
    onchange: null,
    addEventListener: (_type: string, listener: EventListener) => {
      listeners.add(listener);
    },
    removeEventListener: (_type: string, listener: EventListener) => {
      listeners.delete(listener);
    },
    addListener: (listener) => {
      if (listener) listeners.add(listener as EventListener);
    },
    removeListener: (listener) => {
      if (listener) listeners.delete(listener as EventListener);
    },
    dispatchEvent: () => true,
  };

  Object.defineProperty(globalThis, "matchMedia", {
    configurable: true,
    writable: true,
    value: () => mql,
  });

  return (next: boolean) => {
    osDark = next;
    const event = { matches: next, media: mql.media } as MediaQueryListEvent;
    for (const listener of listeners) listener(event);
  };
}

let setOsDark: (osDark: boolean) => void;

function renderToggle() {
  return render(
    <ThemeProvider
      attribute="class"
      defaultTheme={Theme.SYSTEM}
      enableSystem
      storageKey={THEME_STORAGE_KEY}
      disableTransitionOnChange
    >
      <ThemeToggle testId={TEST_ID} />
    </ThemeProvider>,
  );
}

beforeEach(() => {
  setOsDark = installMatchMedia();
  setOsDark(false);
  localStorage.clear();
  document.documentElement.className = "";
});

afterEach(() => {
  localStorage.clear();
  document.documentElement.className = "";
});

describe("ThemeToggle", () => {
  it("cycles light → dark → system, updating aria-label and persisting the choice", () => {
    renderToggle();
    const button = screen.getByTestId(TEST_ID);

    // First visit defaults to system; clicking advances system → light.
    expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.SYSTEM]);

    fireEvent.click(button);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(Theme.LIGHT);
    expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.LIGHT]);
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(false);

    fireEvent.click(button);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(Theme.DARK);
    expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.DARK]);
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(true);

    fireEvent.click(button);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(Theme.SYSTEM);
    expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.SYSTEM]);
  });

  it("applies the `.dark` class on `<html>` when a dark theme is chosen", () => {
    renderToggle();
    const button = screen.getByTestId(TEST_ID);

    // system → light → dark.
    fireEvent.click(button);
    fireEvent.click(button);
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(true);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(Theme.DARK);
  });

  it("resolves to dark on first visit when the OS prefers dark (no stored choice)", () => {
    setOsDark(true);
    renderToggle();

    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBeNull();
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(true);
  });

  it("honours an explicit stored choice and ignores the OS preference", () => {
    localStorage.setItem(THEME_STORAGE_KEY, Theme.DARK);
    setOsDark(false);
    renderToggle();

    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(true);
    expect(screen.getByTestId(TEST_ID)).toHaveAttribute(
      "aria-label",
      NEXT_ACTION_LABEL[Theme.DARK],
    );
  });

  it("follows a live OS scheme change while in system mode", () => {
    renderToggle();
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(false);

    act(() => setOsDark(true));
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(true);

    act(() => setOsDark(false));
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(false);
  });

  it("falls back to system behavior on an unknown stored value and recovers on click", () => {
    localStorage.setItem(THEME_STORAGE_KEY, "not-a-theme");
    renderToggle();
    const button = screen.getByTestId(TEST_ID);

    expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.SYSTEM]);

    fireEvent.click(button);
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe(Theme.LIGHT);
    expect(document.documentElement.classList.contains(Theme.DARK)).toBe(false);
  });

  it("renders and toggles without crashing when localStorage is blocked", () => {
    const blocked: Pick<Storage, "getItem" | "setItem" | "removeItem" | "clear"> = {
      getItem: () => {
        throw new Error("blocked");
      },
      setItem: () => {
        throw new Error("blocked");
      },
      removeItem: () => {
        throw new Error("blocked");
      },
      clear: () => {
        throw new Error("blocked");
      },
    };
    vi.stubGlobal("localStorage", blocked);
    try {
      renderToggle();
      const button = screen.getByTestId(TEST_ID);

      expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.SYSTEM]);

      fireEvent.click(button);
      expect(button).toHaveAttribute("aria-label", NEXT_ACTION_LABEL[Theme.LIGHT]);
    } finally {
      vi.unstubAllGlobals();
    }
  });

  it("exposes an sr-only textual fallback and aria-hidden icon", () => {
    renderToggle();
    const button = screen.getByTestId(TEST_ID);

    expect(button.querySelector(".sr-only")).not.toBeNull();
    expect(button.querySelector("svg")).toHaveAttribute("aria-hidden");
  });
});
