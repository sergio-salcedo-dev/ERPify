import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AccessWall, AccessWallVariant } from "@/context/shared/error/infrastructure/ui/AccessWall";

describe("AccessWall", () => {
  it.each([
    {
      variant: AccessWallVariant.SUSPENDED,
      status: "Suspended",
      testId: "access-wall--suspended",
      signInName: "Sign in",
    },
    {
      variant: AccessWallVariant.DEACTIVATED,
      status: "Deactivated",
      testId: "access-wall--deactivated",
      signInName: "Sign in",
    },
    {
      variant: AccessWallVariant.LOCKED,
      status: "Locked",
      testId: "access-wall--locked",
      signInName: "Sign in",
    },
    {
      variant: AccessWallVariant.INVALID_LINK,
      status: "Invalid link",
      testId: "access-wall--invalid-link",
      signInName: "Sign in",
    },
    {
      variant: AccessWallVariant.INVALID_RESET_LINK,
      status: "Invalid link",
      testId: "access-wall--invalid-reset-link",
      signInName: "Sign in",
    },
  ])(
    "renders the $variant wall as accessible card content",
    ({ variant, status, testId, signInName }) => {
      render(<AccessWall variant={variant} />);

      // Exactly one h1, which receives focus on mount.
      const headings = screen.getAllByRole("heading", { level: 1 });
      expect(headings).toHaveLength(1);
      expect(headings[0]).toHaveFocus();

      // The visible status text is the non-color channel paired with the icon.
      expect(screen.getByText(status)).toBeInTheDocument();

      // A sign-in recovery link back to /login (safeHref) with a static aria-label.
      const signIn = screen.getByRole("link", { name: signInName });
      expect(signIn).toHaveAttribute("href", "/login");

      expect(screen.getByTestId(testId)).toBeInTheDocument();
    },
  );

  it("renders both recovery actions on the locked wall (neutral, two-action stack)", () => {
    render(<AccessWall variant={AccessWallVariant.LOCKED} />);

    // Primary CTA routes to self-service recovery; secondary returns to sign-in.
    const recover = screen.getByRole("link", { name: "Recover access" });
    expect(recover).toHaveAttribute("href", "/forgot-password");
    expect(recover).toHaveAttribute("data-testid", "access-wall__recover--locked");

    const signIn = screen.getByRole("link", { name: "Sign in" });
    expect(signIn).toHaveAttribute("href", "/login");
    expect(signIn).toHaveAttribute("data-testid", "access-wall__sign-in--locked");
  });

  it("renders the invalid-link wall with a two-action stack", () => {
    render(<AccessWall variant={AccessWallVariant.INVALID_LINK} />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
      "This link is no longer valid",
    );
    expect(
      screen.getByText("Ask your administrator for a new invitation to continue."),
    ).toBeInTheDocument();

    // Primary CTA returns to sign-in; secondary asks for a new invitation.
    const signIn = screen.getByRole("link", { name: "Sign in" });
    expect(signIn).toHaveAttribute("href", "/login");
    expect(signIn).toHaveAttribute("data-testid", "access-wall__sign-in--invalid-link");

    const request = screen.getByRole("link", { name: "Request a new invitation" });
    expect(request).toHaveAttribute("href", "/");
    expect(request).toHaveAttribute("data-testid", "access-wall__request-invitation--invalid-link");
  });

  it("renders the invalid-reset-link wall with the same opaque title but a self-service exit", () => {
    const { unmount } = render(<AccessWall variant={AccessWallVariant.INVALID_LINK} />);
    const invitationTitle = screen.getByRole("heading", { level: 1 }).textContent;
    unmount();

    render(<AccessWall variant={AccessWallVariant.INVALID_RESET_LINK} />);

    // The opacity contract protects WHY the link died, not WHICH flow it belongs
    // to (the URL already names the flow) — so the title stays byte-identical
    // and only the exit path differs.
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(invitationTitle ?? "");
    expect(
      screen.getByText(
        "If you already set a new password with this link, it is active — sign in with it. Otherwise, request a new link to reset your password.",
      ),
    ).toBeInTheDocument();

    const signIn = screen.getByRole("link", { name: "Sign in" });
    expect(signIn).toHaveAttribute("href", "/login");
    expect(signIn).toHaveAttribute("data-testid", "access-wall__sign-in--invalid-reset-link");

    const request = screen.getByRole("link", { name: "Request a new link" });
    expect(request).toHaveAttribute("href", "/forgot-password");
    expect(request).toHaveAttribute(
      "data-testid",
      "access-wall__request-reset-link--invalid-reset-link",
    );
  });

  it("tells the reset wall's visitor their new password may already be live", () => {
    // A reset the server applied whose 204 never arrived leaves the visitor here with a working
    // credential and nothing that says so: the retry meets a spent token and every other signal
    // reads as failure. The exit was already on this wall — what was missing is the reason to take
    // it, so the copy is the whole fix.
    render(<AccessWall variant={AccessWallVariant.INVALID_RESET_LINK} />);

    const body = screen.getByTestId("access-wall--invalid-reset-link").textContent ?? "";
    // The condition is on what the VISITOR did, never on what happened to the link: `ResetPasswordForm`
    // renders this same wall when the URL carries no `?token=` at all, so someone who submitted nothing
    // reaches it. "If this link was already used" leaves that person reading a claim about a password
    // they never set; this phrasing is a question they can answer on every path that lands here.
    expect(body).toContain("If you already set a new password with this link");
    expect(body).toContain("sign in with it");
    // Guidance is only guidance while the exit it names is reachable from this same wall.
    expect(screen.getByRole("link", { name: "Sign in" })).toHaveAttribute("href", "/login");
  });

  it("keeps the reset hint off the invitation wall", () => {
    // The two walls are deliberately identical above the description, which is exactly why this
    // needs pinning: the hint is true of a spent RESET link only. An invitation link sets no
    // password, so telling its visitor one is active would send them to sign in with nothing.
    render(<AccessWall variant={AccessWallVariant.INVALID_LINK} />);

    expect(screen.getByTestId("access-wall--invalid-link").textContent ?? "").not.toContain(
      "If you already set a new password with this link",
    );
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
