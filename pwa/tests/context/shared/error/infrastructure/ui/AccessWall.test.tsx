import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui/AccessWall";

describe("AccessWall", () => {
  it.each([
    {
      variant: AccessWallVariant.SUSPENDED,
      status: "Suspended",
      testId: "access-wall--suspended",
    },
    {
      variant: AccessWallVariant.DEACTIVATED,
      status: "Deactivated",
      testId: "access-wall--deactivated",
    },
  ])("renders the $variant wall as accessible card content", ({ variant, status, testId }) => {
    render(<AccessWall variant={variant} />);

    // Exactly one h1, which receives focus on mount.
    const headings = screen.getAllByRole("heading", { level: 1 });
    expect(headings).toHaveLength(1);
    expect(headings[0]).toHaveFocus();

    // The visible status text is the non-color channel paired with the icon.
    expect(screen.getByText(status)).toBeInTheDocument();

    // A "Sign in" recovery link back to /login (safeHref) with a static aria-label.
    const signIn = screen.getByRole("link", { name: "Sign in" });
    expect(signIn).toHaveAttribute("href", "/login");

    expect(screen.getByTestId(testId)).toBeInTheDocument();
  });

  it("uses distinct copy per variant", () => {
    const { unmount } = render(<AccessWall variant={AccessWallVariant.SUSPENDED} />);
    const suspendedTitle = screen.getByRole("heading", { level: 1 }).textContent;
    const suspendedBody = screen.getByTestId("access-wall--suspended").textContent;
    unmount();

    render(<AccessWall variant={AccessWallVariant.DEACTIVATED} />);
    const deactivatedTitle = screen.getByRole("heading", { level: 1 }).textContent;
    const deactivatedBody = screen.getByTestId("access-wall--deactivated").textContent;

    expect(suspendedTitle).not.toBe(deactivatedTitle);
    expect(suspendedBody).not.toBe(deactivatedBody);
  });
});
