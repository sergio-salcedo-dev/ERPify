import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { NavEntryLink } from "@/components/erpify";

function renderEntry(overrides: Partial<Parameters<typeof NavEntryLink>[0]> = {}) {
  const onNavigate = vi.fn();
  render(
    <NavEntryLink
      href="/backoffice/banks"
      onNavigate={onNavigate}
      title="Banks"
      className="entry"
      testId="nav-entry-test__banks"
      {...overrides}
    >
      Banks
    </NavEntryLink>,
  );
  return onNavigate;
}

describe("NavEntryLink", () => {
  it("is a link to its page, so it can be opened in a new tab or copied", () => {
    renderEntry({ isCurrent: true });

    const link = screen.getByRole("link", { name: "Banks" });
    expect(link).toHaveAttribute("href", "/backoffice/banks");
    expect(link).toHaveAttribute("aria-current", "page");
  });

  it("hands a plain click to the consumer instead of navigating on its own", () => {
    const onNavigate = renderEntry();

    const notPrevented = fireEvent.click(screen.getByRole("link", { name: "Banks" }));

    expect(onNavigate).toHaveBeenCalledOnce();
    // The consumer navigates; the link's own navigation is cancelled so it does not happen twice.
    expect(notPrevented).toBe(false);
  });

  it.each([
    ["Ctrl", { ctrlKey: true }],
    ["Cmd", { metaKey: true }],
    ["Shift", { shiftKey: true }],
    ["Alt", { altKey: true }],
    ["middle button", { button: 1 }],
  ])("leaves a %s click to the browser", (_label, modifier) => {
    const onNavigate = renderEntry();

    const notPrevented = fireEvent.click(screen.getByRole("link", { name: "Banks" }), modifier);

    expect(onNavigate).not.toHaveBeenCalled();
    expect(notPrevented).toBe(true);
  });

  it("renders an action as a button, since it opens no page", () => {
    const onNavigate = renderEntry({ isAction: true, isBusy: true });

    expect(screen.queryByRole("link")).toBeNull();
    const button = screen.getByRole("button", { name: "Banks" });
    expect(button).toHaveAttribute("aria-disabled", "true");

    fireEvent.click(button);
    // Busy is announced, never enforced here: the consumer's own in-flight guard drops the click.
    expect(onNavigate).toHaveBeenCalledOnce();
  });
});
