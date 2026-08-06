import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const search = vi.hoisted(() => vi.fn());
const follow = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());
const revoke = vi.hoisted(() => vi.fn());

// The list reads through `useResourceList` and the revocation resolves its use case from the container; the
// session behind both gates hydrates from `/me` through the same container. Dispatch by token and throw on an
// unknown one, so a mis-stubbed token surfaces instead of degrading the view to an error state.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeUserRepository") return { search };
      if (token === "BackOfficeUserSearchNavigator") return { follow };
      if (token === "BackOfficeRevokeInvitation") return { run: revoke };
      if (token === "IdentityRepository") return { me: (...args: unknown[]) => me(...args) };
      if (token === "SessionsRepository") return { revokeCurrent: vi.fn() };
      throw new Error(`Unexpected container token: ${token}`);
    },
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

import UsersListPage from "@/app/backoffice/users/page";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { User } from "@/context/backoffice/user/domain/User";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { Identity } from "@/context/shared/access/domain/Identity";
import { openRowMenuItem } from "../_interactions";

const REVOKER: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ, Permission.USERS_REVOKE_INVITATION],
};

// Reads the register but may not withdraw an invitation — the discriminating row of the gate.
const READER: Identity = {
  ...REVOKER,
  email: "reader@erpify.test",
  permissions: [Permission.USERS_READ],
};

const INVITED_USER = new User(
  "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c",
  "pending@erpify.test",
  UserStatus.INVITED,
  [Role.VIEWER],
  "2026-07-01T10:00:00+00:00",
  "2026-07-01T10:00:00+00:00",
);

// What the register returns for the same identity once its invitation has been withdrawn: the row survives at
// the terminal REVOKED state, which is what the re-read after a successful revocation observes.
const WITHDRAWN_USER = new User(
  INVITED_USER.id,
  INVITED_USER.email,
  UserStatus.REVOKED,
  INVITED_USER.roles,
  INVITED_USER.createdAt,
  "2026-07-02T09:00:00+00:00",
);

const ACTIVE_USER = new User(
  "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d",
  "member@erpify.test",
  UserStatus.ACTIVE,
  [Role.VIEWER],
  "2026-07-01T10:00:00+00:00",
  "2026-07-01T10:00:00+00:00",
);

const INVITED_ROW = `users-table__row-${INVITED_USER.id}`;
const STATUS_BADGE = `users-table__status-${INVITED_USER.id}`;

function renderList() {
  render(
    <AuthProvider>
      <UsersListPage />
    </AuthProvider>,
  );
}

function pageOf(...items: User[]) {
  return {
    items,
    hasNext: false,
    hasPrev: false,
    count: items.length,
    links: { next: null, prev: null },
  };
}

/**
 * The refusal the console can still meet with the action on screen: the invitee accepted (or another operator
 * revoked) between the render and the request, so the row the session is looking at is already stale. The
 * status gate retires the action on the next read, never on the click — which is why the failure needs a
 * persistent surface and not a disabled button.
 */
function nothingRevocable(): HttpError {
  return new HttpError({
    type: "revocable-invitation-not-found",
    title: "No pending invitation was found for the given user.",
    status: HttpStatus.NOT_FOUND,
    instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
    "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
  });
}

describe("Users row revoke-invitation action", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // View and density are persisted preferences: without this a cards-view choice would leak between tests
    // and the table rows would stop existing.
    localStorage.clear();
    search.mockResolvedValue(pageOf(INVITED_USER, ACTIVE_USER));
    me.mockResolvedValue(REVOKER);
  });

  it("offers the action on an INVITED row only", async () => {
    renderList();

    expect(await screen.findByTestId(INVITED_ROW)).toBeInTheDocument();
    expect(screen.getByTestId(`users-table__actions-${INVITED_USER.id}`)).toBeInTheDocument();
    // The ACTIVE row has nothing to withdraw, so it carries no overflow menu at all.
    expect(screen.queryByTestId(`users-table__actions-${ACTIVE_USER.id}`)).not.toBeInTheDocument();
  });

  it("hides the action from a session without users.revokeInvitation", async () => {
    me.mockResolvedValue(READER);
    renderList();

    expect(await screen.findByTestId(INVITED_ROW)).toBeInTheDocument();
    expect(screen.queryByTestId(`users-table__actions-${INVITED_USER.id}`)).not.toBeInTheDocument();
  });

  it("revokes through the use case, toasts, and the re-read shows the row withdrawn", async () => {
    revoke.mockResolvedValue(undefined);
    // The register answers the reconciling read with the withdrawn row, which is what the API leaves behind.
    search
      .mockResolvedValueOnce(pageOf(INVITED_USER, ACTIVE_USER))
      .mockResolvedValue(pageOf(WITHDRAWN_USER, ACTIVE_USER));
    renderList();
    await screen.findByTestId(INVITED_ROW);
    expect(screen.getByTestId(STATUS_BADGE)).toHaveTextContent("Invited");

    fireEvent.click(await openRowMenuItem("users-table", "revoke", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-revoke-invitation__confirm"));

    await waitFor(() => expect(revoke).toHaveBeenCalledWith(INVITED_USER.id));
    expect(toastNotifier.success).toHaveBeenCalledWith("Invitation revoked", {
      description: INVITED_USER.email,
    });
    // The identity is withdrawn, not removed: the list reconciles by re-reading, the row keeps its place and
    // its badge flips.
    await waitFor(() => expect(search).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByTestId(STATUS_BADGE)).toHaveTextContent("Revoked"));
    expect(screen.getByTestId(INVITED_ROW)).toBeInTheDocument();
    expect(screen.queryByTestId("users-list__revoke-error")).not.toBeInTheDocument();
  });

  it("retires the row action once the identity is withdrawn, with no client-side bookkeeping", async () => {
    revoke.mockResolvedValue(undefined);
    search
      .mockResolvedValueOnce(pageOf(INVITED_USER, ACTIVE_USER))
      .mockResolvedValue(pageOf(WITHDRAWN_USER, ACTIVE_USER));
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "revoke", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-revoke-invitation__confirm"));

    // The menu is gated on the row's own status, so the reconciling read removes it — the component never
    // tracks which rows it has already revoked.
    await waitFor(() => {
      expect(
        screen.queryByTestId(`users-table__actions-${INVITED_USER.id}`),
      ).not.toBeInTheDocument();
    });
  });

  it("closes the dialog and anchors a failure in the list-level error surface", async () => {
    revoke.mockRejectedValue(nothingRevocable());
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "revoke", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-revoke-invitation__confirm"));

    // The problem surfaces above the list, never inside the dialog — which closes itself.
    const surface = await screen.findByTestId("users-list__revoke-error");
    expect(surface).toHaveTextContent("No pending invitation was found for the given user.");
    await waitFor(() => {
      expect(screen.queryByTestId("user-revoke-invitation__dialog")).not.toBeInTheDocument();
    });
    // A failed revocation leaves the register untouched, so nothing is re-read.
    expect(search).toHaveBeenCalledTimes(1);
    expect(toastNotifier.error).toHaveBeenCalled();
  });

  it("dismisses the persistent error surface on demand", async () => {
    revoke.mockRejectedValue(nothingRevocable());
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "revoke", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-revoke-invitation__confirm"));
    await screen.findByTestId("users-list__revoke-error");

    fireEvent.click(screen.getByTestId("users-list__revoke-error__dismiss"));

    await waitFor(() => {
      expect(screen.queryByTestId("users-list__revoke-error")).not.toBeInTheDocument();
    });
  });
});
