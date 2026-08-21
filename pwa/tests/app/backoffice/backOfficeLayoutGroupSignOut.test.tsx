import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const { push, logout, replace, auth, override, GROUP_LOGOUT_TEST_ID, GROUP_PARENT_TEST_ID } =
  vi.hoisted(() => ({
    push: vi.fn(),
    logout: vi.fn(() => Promise.resolve()),
    replace: vi.fn(),
    override: vi.fn(),
    auth: { session: null as Session | null, status: "authenticated" as string },
    GROUP_LOGOUT_TEST_ID: "bo-layout-decoy__group-sign-out",
    GROUP_PARENT_TEST_ID: "bo-layout-decoy__group-parent",
  }));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ ...auth, login: vi.fn(), logout, override, setIsSigningOut: vi.fn() }),
}));

vi.mock("next/navigation", () => {
  const router = { push, replace: vi.fn(), refresh: vi.fn(), back: vi.fn(), prefetch: vi.fn() };
  return { useRouter: () => router, usePathname: () => "/backoffice" };
});

/**
 * The navigation model with the sign-out intent moved onto a GROUP sub-item.
 *
 * `action` lives on `NavSubItem`, which every group shares, so this is legal in types and the
 * mobile drawer's group branch forwards it — but the real model declares the intent on exactly
 * one account entry, and a model guard holds it there. That guard is why nothing can reach this
 * branch today, and it is also why the branch cannot be shown against the real model: it has to
 * be extended here, and nowhere else.
 *
 * The branch is worth rendering correctly anyway. A guard on the INPUT is not a substitute for
 * a correct render — it means the day the model legitimately grows a second intent-bearing
 * entry, the code half-supports it: the click signs the user out while the button keeps its idle
 * label and stays operable, on the one surface where nothing else says anything either.
 */
vi.mock("@/app/backoffice/_lib/backofficeMenu", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/app/backoffice/_lib/backofficeMenu")>();
  const { Routes } = await import("@/context/shared/routing/domain/Routes");
  const [firstGroup, ...restGroups] = actual.backofficeMenuGroups;
  const [firstItem, ...restItems] = firstGroup.items;
  return {
    ...actual,
    backofficeMenuGroups: [
      {
        ...firstGroup,
        items: [
          {
            ...firstItem,
            testId: GROUP_PARENT_TEST_ID,
            subItems: [
              ...(firstItem.subItems ?? []),
              {
                name: "Sign out from here",
                path: Routes.HOME,
                testId: GROUP_LOGOUT_TEST_ID,
                action: "sign-out",
              },
            ],
          },
          ...restItems,
        ],
      },
      ...restGroups,
    ],
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

const LEAVING_LABEL = "Signing out…";

describe("a group sub-item carrying the sign-out intent", () => {
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

  it("renders the busy state on every surface that forwards it", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      // Held open so the window is observable: the drawer closes on activation, so the entry is
      // asserted on a re-open rather than in the instant after the click.
      logout.mockImplementationOnce(() => new Promise<void>(() => {}));
      render(
        <BackOfficeLayoutClient>
          <p>Section content</p>
        </BackOfficeLayoutClient>,
      );
      fireEvent.click(screen.getByLabelText("Open navigation menu"));

      fireEvent.click(await screen.findByTestId(`${GROUP_LOGOUT_TEST_ID}--mobile`));
      expect(logout).toHaveBeenCalledTimes(1);

      fireEvent.click(screen.getByLabelText("Open navigation menu"));
      const entry = await screen.findByTestId(`${GROUP_LOGOUT_TEST_ID}--mobile`);

      await waitFor(() => expect(entry).toHaveTextContent(LEAVING_LABEL));
      expect(entry).toHaveAttribute("aria-disabled", "true");
      expect(entry).toHaveAttribute("title", LEAVING_LABEL);

      // The desktop sidebar too. Its group items go through SidebarItem, which forwards
      // `action` to the click handler but was only ever handed `isBusy` for the ACCOUNT item —
      // so fixing the drawer alone left the same half-support one surface over, uncovered.
      fireEvent.click(screen.getByTestId(GROUP_PARENT_TEST_ID));
      const sidebarEntry = await screen.findByTestId(GROUP_LOGOUT_TEST_ID);
      expect(sidebarEntry).toHaveTextContent(LEAVING_LABEL);
      expect(sidebarEntry).toHaveAttribute("aria-disabled", "true");
    } finally {
      vi.useRealTimers();
    }
  });
});
