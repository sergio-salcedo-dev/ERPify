import { describe, it, expect, beforeEach, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const search = vi.hoisted(() => vi.fn());
const follow = vi.hoisted(() => vi.fn());
const me = vi.hoisted(() => vi.fn());
const resend = vi.hoisted(() => vi.fn());
const revoke = vi.hoisted(() => vi.fn());

// Same container dispatch as the revocation suite: an unknown token throws, so a mis-stubbed one surfaces
// instead of degrading the list to an error state.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeUserRepository") return { search };
      if (token === "BackOfficeUserSearchNavigator") return { follow };
      if (token === "BackOfficeResendInvitation") return { run: resend };
      if (token === "BackOfficeRevokeInvitation") return { run: revoke };
      if (token === "IdentityRepository") return { me: (...args: unknown[]) => me(...args) };
      if (token === "SessionsRepository") return { revokeCurrent: vi.fn() };
      throw new Error(`Unexpected container token: ${token}`);
    },
  },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
  usePathname: () => "/backoffice/users",
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    dismissAll: vi.fn(),
  },
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

const RESENDER: Identity = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  email: "admin@erpify.test",
  status: UserStatus.ACTIVE,
  roles: [Role.ADMIN],
  permissions: [Permission.USERS_READ, Permission.USERS_RESEND_INVITATION],
};

// May withdraw an invitation but not re-send one — the menu opens, without the resend item.
const REVOKER_ONLY: Identity = {
  ...RESENDER,
  email: "revoker@erpify.test",
  permissions: [Permission.USERS_READ, Permission.USERS_REVOKE_INVITATION],
};

const INVITED_USER = new User(
  "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c",
  "pending@erpify.test",
  UserStatus.INVITED,
  [Role.VIEWER],
  "2026-07-01T10:00:00+00:00",
  "2026-07-01T10:00:00+00:00",
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

/** The new link was committed and the old one is dead, but the mailer refused the send. */
function emailNotSent(): HttpError {
  return new HttpError({
    type: "invitation-email-not-sent",
    title:
      "A new invitation link was issued, but the email could not be sent. The previous link no longer works; resend the invitation once email delivery is restored.",
    status: HttpStatus.SERVICE_UNAVAILABLE,
    instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
    "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
  });
}

describe("Users row resend-invitation action", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    search.mockResolvedValue(pageOf(INVITED_USER, ACTIVE_USER));
    me.mockResolvedValue(RESENDER);
  });

  it("offers the resend on an INVITED row only", async () => {
    renderList();

    expect(await screen.findByTestId(INVITED_ROW)).toBeInTheDocument();
    expect(await openRowMenuItem("users-table", "resend", INVITED_USER.id)).toHaveTextContent(
      "Resend invitation",
    );
    // This session cannot withdraw, so the menu carries the resend alone.
    expect(screen.queryByTestId(`users-table__revoke-${INVITED_USER.id}`)).not.toBeInTheDocument();
    expect(screen.queryByTestId(`users-table__actions-${ACTIVE_USER.id}`)).not.toBeInTheDocument();
  });

  it("hides the resend from a session without users.resendInvitation", async () => {
    me.mockResolvedValue(REVOKER_ONLY);
    renderList();
    await screen.findByTestId(INVITED_ROW);

    expect(await openRowMenuItem("users-table", "revoke", INVITED_USER.id)).toBeInTheDocument();
    expect(screen.queryByTestId(`users-table__resend-${INVITED_USER.id}`)).not.toBeInTheDocument();
  });

  it("resends through the use case, toasts, and re-reads with the row still pending", async () => {
    resend.mockResolvedValue(undefined);
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "resend", INVITED_USER.id));
    // The copy warns that the delivered link dies, because that is the one irreversible part of the act.
    expect(await screen.findByTestId("user-resend-invitation__dialog")).toHaveTextContent(
      "The link they already received stops working immediately.",
    );
    fireEvent.click(screen.getByTestId("user-resend-invitation__confirm"));

    await waitFor(() => expect(resend).toHaveBeenCalledWith(INVITED_USER.id));
    expect(revoke).not.toHaveBeenCalled();
    expect(toastNotifier.success).toHaveBeenCalledWith("Invitation resent", {
      description: INVITED_USER.email,
    });
    await waitFor(() => expect(search).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId(STATUS_BADGE)).toHaveTextContent("Invited");
    expect(screen.queryByTestId("users-list__invitation-error")).not.toBeInTheDocument();
  });

  it("lands focus in the row it acted on instead of stranding it on the document", async () => {
    resend.mockResolvedValue(undefined);
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "resend", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-resend-invitation__confirm"));

    const cluster = await screen.findByTestId(`users-table__rowactions-${INVITED_USER.id}`);
    await waitFor(() => expect(cluster).toContainElement(document.activeElement as HTMLElement));
    expect(document.activeElement).not.toBe(document.body);
  });

  it("does not resend when the operator cancels", async () => {
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "resend", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-resend-invitation__cancel"));

    await waitFor(() => {
      expect(screen.queryByTestId("user-resend-invitation__dialog")).not.toBeInTheDocument();
    });
    expect(resend).not.toHaveBeenCalled();
  });

  it("closes the dialog and anchors a refused send in the list-level error surface", async () => {
    resend.mockRejectedValue(emailNotSent());
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(await openRowMenuItem("users-table", "resend", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-resend-invitation__confirm"));

    // The API's title is what tells the operator the old link is dead and what to do next; the surface
    // renders it rather than a client-side paraphrase.
    const surface = await screen.findByTestId("users-list__invitation-error");
    expect(surface).toHaveTextContent("The previous link no longer works");
    await waitFor(() => {
      expect(screen.queryByTestId("user-resend-invitation__dialog")).not.toBeInTheDocument();
    });
    await waitFor(() => expect(search).toHaveBeenCalledTimes(2));
    expect(toastNotifier.error).toHaveBeenCalledWith(
      "Couldn't resend the invitation — see error details",
    );
  });

  it("resends from the cards view", async () => {
    resend.mockResolvedValue(undefined);
    renderList();
    await screen.findByTestId(INVITED_ROW);

    fireEvent.click(screen.getByTestId("users-list__view-toggle-cards"));
    await screen.findByTestId(`users-cards__item-${INVITED_USER.id}`);

    fireEvent.click(await openRowMenuItem("users-cards", "resend", INVITED_USER.id));
    fireEvent.click(await screen.findByTestId("user-resend-invitation__confirm"));

    await waitFor(() => expect(resend).toHaveBeenCalledWith(INVITED_USER.id));
  });
});
