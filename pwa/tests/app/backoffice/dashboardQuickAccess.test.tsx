import { describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";

const { auth } = vi.hoisted(() => ({ auth: { session: null as Session | null } }));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ session: auth.session, status: "authenticated" }),
}));

import { DashboardQuickAccess } from "@/app/backoffice/_components/DashboardQuickAccess";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Session } from "@/context/shared/access/domain/Session";

function sessionWith(permissions: Session["permissions"]): Session {
  return {
    user: {
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "ada@erpify.test",
      status: UserStatus.ACTIVE,
      roles: ["ADMIN"],
      permissions,
    },
    roles: ["ADMIN"],
    permissions,
    context: AccessContext.BACKOFFICE,
  };
}

describe("DashboardQuickAccess", () => {
  it("links every live page, grouped as the sidebar groups it, and no planned one", () => {
    auth.session = sessionWith([Permission.USERS_READ]);
    render(<DashboardQuickAccess />);

    const finance = screen.getByRole("navigation", { name: "Finance" });
    expect(within(finance).getByRole("link", { name: "Banks" })).toHaveAttribute(
      "href",
      `${Routes.BACKOFFICE}/banks`,
    );
    expect(within(finance).queryByRole("link", { name: "Cash Flow" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Invoicing" })).toBeNull();
    // The dashboard does not link to itself.
    expect(screen.queryByRole("link", { name: "Dashboard" })).toBeNull();

    expect(screen.getByTestId("dashboard__quick-access-roadmap-link")).toHaveAttribute(
      "href",
      `${Routes.BACKOFFICE}/roadmap`,
    );
  });

  it("offers no page the session's permissions withhold", () => {
    auth.session = sessionWith([]);
    render(<DashboardQuickAccess />);

    expect(screen.queryByRole("link", { name: "Users" })).toBeNull();
    expect(screen.getByRole("link", { name: "Audit Logs" })).toBeInTheDocument();
  });
});
