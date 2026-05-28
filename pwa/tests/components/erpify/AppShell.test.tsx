import { afterEach, describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AppShell } from "@/components/erpify/AppShell";

describe("AppShell", () => {
  afterEach(() => {
    globalThis.localStorage.clear();
  });

  it("renders nav items with active state and accessible link", () => {
    render(
      <AppShell
        navigation={[
          { href: "/banks", label: "Banks", active: true },
          { href: "/invoices", label: "Invoices" },
        ]}
      >
        content
      </AppShell>,
    );
    const banksLink = screen.getByRole("link", { name: "Banks" });
    expect(banksLink).toHaveAttribute("aria-current", "page");
    const invoicesLink = screen.getByRole("link", { name: "Invoices" });
    expect(invoicesLink).not.toHaveAttribute("aria-current");
  });

  it("includes a skip-to-content link", () => {
    render(<AppShell navigation={[]}>main</AppShell>);
    expect(screen.getByText("Skip to main content")).toHaveAttribute("href", "#main-content");
  });

  it("toggles sidebar on collapse button click and persists to localStorage", () => {
    render(<AppShell navigation={[{ href: "/", label: "Home" }]}>content</AppShell>);
    const toggle = screen.getByRole("button", { name: /Collapse sidebar/i });
    fireEvent.click(toggle);
    expect(globalThis.localStorage.getItem("erpify:sidebar-open")).toBe("0");
    expect(screen.getByRole("button", { name: /Expand sidebar/i })).toBeInTheDocument();
  });

  it("renders children inside main landmark", () => {
    render(
      <AppShell navigation={[]}>
        <div data-testid="page-body">page</div>
      </AppShell>,
    );
    const main = screen.getByRole("main");
    expect(main.querySelector('[data-testid="page-body"]')).toBeTruthy();
  });

  it("renders the topBarRight slot when provided", () => {
    render(
      <AppShell navigation={[]} topBarRight={<button>Profile</button>}>
        content
      </AppShell>,
    );
    expect(screen.getByRole("button", { name: "Profile" })).toBeInTheDocument();
  });
});
