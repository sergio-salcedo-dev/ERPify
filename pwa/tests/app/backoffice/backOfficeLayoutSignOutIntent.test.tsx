import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const { push, logout, replace, auth, override, DECOY_TEST_ID } = vi.hoisted(() => ({
  push: vi.fn(),
  logout: vi.fn(() => Promise.resolve()),
  replace: vi.fn(),
  override: vi.fn(),
  auth: { session: null as Session | null, status: "authenticated" as string },
  DECOY_TEST_ID: "bo-layout-decoy__public-landing",
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout, override }),
}));

vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/backoffice" };
});

/**
 * The navigation model with one entry added: an ordinary link aimed at the public landing, the
 * single most likely destination for a future "Back to site" entry. This is the regression the
 * intent discriminator exists to prevent, and it cannot be shown against the real model — which
 * declares no such entry — so the model is extended here and nowhere else.
 */
vi.mock("@/app/backoffice/_lib/backofficeMenu", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/app/backoffice/_lib/backofficeMenu")>();
  // Imported inside the factory: the call is hoisted above this file's imports, so a top-level
  // binding would not be initialised yet.
  const { Routes } = await import("@/context/shared/routing/domain/Routes");
  return {
    ...actual,
    accountMenuItem: {
      ...actual.accountMenuItem,
      subItems: [
        ...(actual.accountMenuItem.subItems ?? []),
        { name: "Back to site", path: Routes.HOME, testId: DECOY_TEST_ID },
      ],
    },
  };
});

import BackOfficeLayoutClient from "@/app/backoffice/BackOfficeLayoutClient";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";

const SESSION: Session = {
  user: {
    id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
    email: "ada@erpify.test",
    status: UserStatus.ACTIVE,
    roles: ["ADMIN"],
    permissions: [Permission.USERS_READ],
  },
  roles: ["ADMIN"],
  permissions: [Permission.USERS_READ],
  context: AccessContext.BACKOFFICE,
};

describe("an ordinary menu entry aimed at the public landing", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    globalThis.localStorage.clear();
    vi.stubGlobal("location", {
      href: "https://localhost/backoffice",
      origin: "https://localhost",
      pathname: Routes.BACKOFFICE,
      search: "",
      replace,
    });
    auth.session = SESSION;
    auth.status = "authenticated";
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("routes there and leaves the session alone", async () => {
    render(
      <BackOfficeLayoutClient>
        <p>Section content</p>
      </BackOfficeLayoutClient>,
    );
    fireEvent.click(screen.getByLabelText("Open navigation menu"));

    fireEvent.click(await screen.findByTestId(`${DECOY_TEST_ID}--mobile`));

    await waitFor(() => expect(push).toHaveBeenCalledWith(Routes.HOME));
    // Keyed on the destination, this entry would have revoked the session — silently, from a
    // menu file that never mentions authentication.
    expect(logout).not.toHaveBeenCalled();
    expect(replace).not.toHaveBeenCalled();
  });
});
