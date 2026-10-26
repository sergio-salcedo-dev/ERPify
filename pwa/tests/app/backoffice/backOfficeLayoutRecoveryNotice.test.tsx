import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { RecoveryReadiness } from "@/context/shared/access/domain/RecoveryReadiness";

const { auth, shell } = vi.hoisted(() => ({
  auth: { session: null as Session | null, status: "authenticated" as string },
  shell: { readiness: { kind: "unknown" } as RecoveryReadiness },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout: vi.fn(), override: vi.fn() }),
}));

vi.mock("@/context/shared/access/application/useRecoveryReadiness", () => ({
  useRecoveryReadiness: () => ({ readiness: shell.readiness, publish: vi.fn() }),
  useRecoveryReadinessPublisher: () => vi.fn(),
}));
vi.mock("@/context/shared/access/infrastructure/ui/RecoveryReadinessProvider", () => ({
  RecoveryReadinessProvider: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock("next/navigation", () => {
  const router = { push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/backoffice" };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Session } from "@/context/shared/access/domain/Session";
import { Routes } from "@/context/shared/routing/domain/Routes";

function sessionWith(roles: Role[]): Session {
  return {
    user: {
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "ada@erpify.test",
      status: UserStatus.ACTIVE,
      roles,
      permissions: [],
    },
    roles,
    permissions: [],
    context: AccessContext.BACKOFFICE,
  };
}

function renderShell(): void {
  render(
    <BackOfficeLayoutClient>
      <p>Section</p>
    </BackOfficeLayoutClient>,
  );
}

function notice(): HTMLElement | null {
  return screen.queryByRole("complementary", { name: "Account recovery" });
}

/**
 * The warning an administrator without a live recovery secret sees on every back-office page.
 * Its two gates are read from where they are decided — the session's server-issued roles and the
 * server's `live` verdict — and the second is three-valued: only a KNOWN `not-live` warns.
 */
describe("BackOfficeLayoutClient — recovery readiness notice", () => {
  beforeEach(() => {
    globalThis.localStorage.clear();
    auth.session = sessionWith(["ADMIN"]);
    shell.readiness = { kind: "unknown" };
  });

  it("warns an administrator holding no secret, and links to where one is created", () => {
    shell.readiness = { kind: "not-live", reason: "none" };
    renderShell();

    const landmark = notice();
    expect(landmark).toHaveTextContent(
      "Your account has no recovery secret. If you are ever locked out, it is how you get back in.",
    );
    expect(
      screen.getByRole("link", { name: "Create one in Profile → Recovery secret" }),
    ).toHaveAttribute("href", Routes.PROFILE);
  });

  it("warns an administrator whose secret has lapsed, naming the way out", () => {
    shell.readiness = { kind: "not-live", reason: "expired" };
    renderShell();

    expect(notice()).toHaveTextContent(
      "Your recovery secret has expired and no longer signs you in. Revoke it, then create a new one.",
    );
    expect(screen.getByRole("link", { name: "Go to Profile → Recovery secret" })).toHaveAttribute(
      "href",
      Routes.PROFILE,
    );
  });

  it("says nothing to an administrator whose secret is live", () => {
    shell.readiness = { kind: "live" };
    renderShell();

    expect(notice()).toBeNull();
  });

  it("says nothing while the verdict is unknown — a read in flight or one that failed", () => {
    renderShell();

    expect(notice()).toBeNull();
  });

  it("says nothing to a session without ADMIN, whatever its secret's state", () => {
    auth.session = sessionWith(["MANAGER", "AUDIT_READER"]);
    shell.readiness = { kind: "not-live", reason: "none" };
    renderShell();

    expect(notice()).toBeNull();
  });

  it("sits first inside <main>, so the focus a navigation moves there reaches it first", () => {
    shell.readiness = { kind: "not-live", reason: "none" };
    renderShell();

    const main = globalThis.document.querySelector("main");
    expect(main?.firstElementChild).toBe(notice());
  });

  it("is never a live region and offers no dismiss", () => {
    shell.readiness = { kind: "not-live", reason: "none" };
    renderShell();

    const landmark = notice();
    expect(landmark).not.toBeNull();
    expect(
      landmark?.querySelector("output, [role='status'], [role='alert'], [aria-live]"),
    ).toBeNull();
    expect(landmark?.querySelector("button")).toBeNull();
  });
});
