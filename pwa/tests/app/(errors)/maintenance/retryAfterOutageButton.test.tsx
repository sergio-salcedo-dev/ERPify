import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace, refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/maintenance" };
});

import MaintenancePage from "@/app/(errors)/maintenance/page";
import { RetryAfterOutageButton } from "@/app/(errors)/maintenance/_components/RetryAfterOutageButton";

function landOnMaintenance(search: string): void {
  globalThis.history.replaceState(null, "", `/maintenance${search}`);
}

function retry(): void {
  fireEvent.click(screen.getByTestId("maintenance__retry-button"));
}

beforeEach(() => {
  replace.mockReset();
  landOnMaintenance("");
});

describe("RetryAfterOutageButton", () => {
  it("returns to the interrupted route carried in ?next=", () => {
    landOnMaintenance(`?next=${encodeURIComponent("/backoffice/users?page=2")}`);
    render(<RetryAfterOutageButton />);

    retry();

    expect(replace).toHaveBeenCalledTimes(1);
    expect(replace).toHaveBeenCalledWith("/backoffice/users?page=2");
  });

  it.each(["//evil.com", "https://evil.com", "/\\evil.com", "javascript:alert(1)", "/\t/evil.com"])(
    "falls back to the back-office root for a hostile ?next=%s",
    (hostile) => {
      landOnMaintenance(`?next=${encodeURIComponent(hostile)}`);
      render(<RetryAfterOutageButton />);

      retry();

      expect(replace).toHaveBeenCalledWith("/backoffice");
    },
  );

  it.each(["/maintenance", "/maintenance/", "/maintenance?next=%2Fbackoffice", "/maintenance#top"])(
    "falls back to the back-office root when ?next=%s names this page, which would re-probe nothing",
    (self) => {
      landOnMaintenance(`?next=${encodeURIComponent(self)}`);
      render(<RetryAfterOutageButton />);

      retry();

      expect(replace).toHaveBeenCalledWith("/backoffice");
    },
  );

  it("keeps a route that merely starts with the page's name", () => {
    landOnMaintenance(`?next=${encodeURIComponent("/maintenance-windows")}`);
    render(<RetryAfterOutageButton />);

    retry();

    expect(replace).toHaveBeenCalledWith("/maintenance-windows");
  });

  it("falls back to the back-office root when no ?next= was carried", () => {
    render(<RetryAfterOutageButton />);

    retry();

    expect(replace).toHaveBeenCalledWith("/backoffice");
  });

  it("names itself for assistive tech and on hover", () => {
    render(<RetryAfterOutageButton />);

    const button = screen.getByTestId("maintenance__retry-button");
    expect(button.getAttribute("aria-label")).toBe("Try again");
    expect(button.getAttribute("title")).toBeTruthy();
    expect(button.getAttribute("type")).toBe("button");
  });
});

describe("MaintenancePage", () => {
  it("speaks of an unavailable service, not a scheduled window, and offers the retry", () => {
    render(<MaintenancePage />);

    expect(screen.getByTestId("maintenance__title").textContent).toBe("Service unavailable");
    // 502 and 504 land here as well as 503, so the status line names no single code.
    expect(screen.getByTestId("maintenance__status").textContent).not.toMatch(/\d{3}/);
    expect(screen.getByTestId("maintenance__description").textContent).not.toMatch(/scheduled/i);
    expect(screen.getByTestId("maintenance__retry-button")).toBeTruthy();
    expect(screen.getByTestId("error-actions__home-link")).toBeTruthy();
  });
});
