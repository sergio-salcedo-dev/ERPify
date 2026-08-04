import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { Session } from "@/context/shared/access/domain/Session";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { Permission } from "@/context/shared/access/domain/Permission";

const SESSION: Session = {
  user: {
    id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
    email: "ada@erpify.test",
    status: UserStatus.ACTIVE,
    roles: ["ADMIN", "AUDIT_READER"],
    permissions: [Permission.USERS_READ],
  },
  roles: ["ADMIN", "AUDIT_READER"],
  permissions: [Permission.USERS_READ],
  context: AccessContext.BACKOFFICE,
};

let session: Session | null = SESSION;
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ session, status: "authenticated" }),
}));

// The change-password form resolves its port from the DI container; mock that boundary
// so mounting the page never reaches the network.
const changePassword = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({ changePassword: (...args: unknown[]) => changePassword(...args) }),
  },
}));

import ProfilePage, { metadata } from "@/app/backoffice/profile/page";

describe("Backoffice profile page", () => {
  it("declares the profile page title and keeps it out of search indexes", () => {
    expect(metadata.title).toBe("My profile");
    expect(metadata.robots).toMatchObject({ index: false });
  });

  it("renders the identity the session actually carries", () => {
    session = SESSION;

    render(<ProfilePage />);

    expect(screen.getByRole("heading", { level: 1, name: "My profile" })).toBeInTheDocument();
    expect(screen.getByTestId("account-profile__field-email")).toHaveTextContent("ada@erpify.test");
    expect(screen.getByTestId("account-profile__id")).toHaveTextContent(SESSION.user.id);
    expect(screen.getByTestId("account-profile__id-copy")).toBeInTheDocument();
    expect(screen.getByTestId("account-profile__role-ADMIN")).toBeInTheDocument();
    expect(screen.getByTestId("account-profile__role-AUDIT_READER")).toBeInTheDocument();
    expect(screen.getByTestId("account-profile__permission-users.read")).toBeInTheDocument();
  });

  // `Identity.status` is synthesised client-side from the 200 — /me never sends it — so
  // rendering it would assert something the response did not say.
  it("renders no status field for the identity", () => {
    session = SESSION;

    render(<ProfilePage />);

    expect(screen.queryByText("Status")).not.toBeInTheDocument();
    expect(screen.queryByText(UserStatus.ACTIVE)).not.toBeInTheDocument();
  });

  it("mounts the change-password form under the security heading", () => {
    session = SESSION;

    render(<ProfilePage />);

    expect(screen.getByRole("heading", { level: 2, name: "Security" })).toBeInTheDocument();
    expect(screen.getByTestId("change-password-form")).toBeInTheDocument();
  });

  it("renders nothing while there is no resolved session", () => {
    session = null;

    const { container: mounted } = render(<ProfilePage />);

    expect(mounted).toBeEmptyDOMElement();
    session = SESSION;
  });
});
