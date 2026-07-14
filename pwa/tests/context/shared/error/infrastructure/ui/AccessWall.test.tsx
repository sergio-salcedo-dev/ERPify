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
      status: "Enlace no válido",
      testId: "access-wall--invalid-link",
      signInName: "Iniciar sesión",
    },
    {
      variant: AccessWallVariant.INVALID_RESET_LINK,
      status: "Enlace no válido",
      testId: "access-wall--invalid-reset-link",
      signInName: "Iniciar sesión",
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

  it("renders the invalid-link wall in Spanish with a two-action stack", () => {
    render(<AccessWall variant={AccessWallVariant.INVALID_LINK} />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
      "Este enlace ya no es válido",
    );
    expect(
      screen.getByText("Solicita una nueva invitación a tu administrador para continuar."),
    ).toBeInTheDocument();

    // Primary CTA returns to sign-in; secondary asks for a new invitation.
    const signIn = screen.getByRole("link", { name: "Iniciar sesión" });
    expect(signIn).toHaveAttribute("href", "/login");
    expect(signIn).toHaveAttribute("data-testid", "access-wall__sign-in--invalid-link");

    const request = screen.getByRole("link", { name: "Solicitar nueva invitación" });
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
      screen.getByText("Puedes solicitar un enlace nuevo desde «¿Has olvidado tu contraseña?»."),
    ).toBeInTheDocument();

    const signIn = screen.getByRole("link", { name: "Iniciar sesión" });
    expect(signIn).toHaveAttribute("href", "/login");
    expect(signIn).toHaveAttribute("data-testid", "access-wall__sign-in--invalid-reset-link");

    const request = screen.getByRole("link", { name: "Solicitar enlace nuevo" });
    expect(request).toHaveAttribute("href", "/forgot-password");
    expect(request).toHaveAttribute(
      "data-testid",
      "access-wall__request-reset-link--invalid-reset-link",
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
