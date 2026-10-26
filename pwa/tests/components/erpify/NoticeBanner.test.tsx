import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { NoticeBanner } from "@/components/erpify/NoticeBanner";

function renderBanner(href = "/backoffice/profile") {
  return render(
    <NoticeBanner
      label="Account recovery"
      tone="warning"
      text="Your account has no recovery secret."
      link={{ href, label: "Create one" }}
      testId="notice"
    />,
  );
}

describe("NoticeBanner", () => {
  it("is a named complementary landmark carrying its text and the link that resolves it", () => {
    renderBanner();

    const landmark = screen.getByRole("complementary", { name: "Account recovery" });
    expect(landmark).toHaveTextContent("Your account has no recovery secret.");
    expect(screen.getByRole("link", { name: "Create one" })).toHaveAttribute(
      "href",
      "/backoffice/profile",
    );
  });

  it("is never a live region, so standing on every page never re-announces it", () => {
    const { container } = renderBanner();

    expect(screen.queryByRole("status")).toBeNull();
    expect(screen.queryByRole("alert")).toBeNull();
    expect(container.querySelector("output")).toBeNull();
    expect(container.querySelector("[aria-live]")).toBeNull();
  });

  it("offers no dismiss — the link is its only control", () => {
    renderBanner();

    expect(screen.queryByRole("button")).toBeNull();
  });

  it("carries its tone in the StatusBadge dot, hidden from assistive technology", () => {
    const { container } = renderBanner();

    const dot = container.querySelector("span[aria-hidden='true']");
    expect(dot).toHaveClass("bg-status-dot-warning");
  });

  it.each(["javascript:alert(1)", "JavaScript:alert(1)"])(
    "falls back to # for a script URL as its link target (%s)",
    (href) => {
      renderBanner(href);

      expect(screen.getByRole("link", { name: "Create one" })).toHaveAttribute("href", "#");
    },
  );
});
