import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const mocks = vi.hoisted(() => ({
  inviteRun: vi.fn(),
  push: vi.fn(),
  refresh: vi.fn(),
  me: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: mocks.push, refresh: mocks.refresh, back: vi.fn() }),
}));
// The form resolves its use case from the container, and the `users.grantAdmin` gate hydrates the session
// from `/me` through that same container. Dispatch by token and throw on an unknown one: a silently
// mis-stubbed token would leave the session null and make every "the checkbox is absent" assertion pass for
// the wrong reason.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeInviteUser") return { run: mocks.inviteRun };
      if (token === "IdentityRepository") return { me: (...args: unknown[]) => mocks.me(...args) };
      if (token === "SessionsRepository") return { revokeCurrent: vi.fn() };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));
vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

import { InviteUserForm } from "@/app/backoffice/users/_components/InviteUserForm";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { useSession } from "@/context/shared/access/application/useSession";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { Identity } from "@/context/shared/access/domain/Identity";

const ADMIN_CHECKBOX = `invite-user-form__role-${Role.ADMIN}`;
const NO_SESSION = "none";

function identity(
  email: string,
  permissions: Permission[],
  status: UserStatus = UserStatus.ACTIVE,
): Identity {
  return {
    id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    email,
    status,
    roles: [Role.ADMIN],
    permissions,
  };
}

// Holds both capabilities — may invite and may mint another administrator.
const GRANTER = identity("granter@erpify.test", [
  Permission.USERS_INVITE,
  Permission.USERS_GRANT_ADMIN,
]);
// May invite, may not delegate the role: the discriminating row of the truth table.
const INVITER = identity("inviter@erpify.test", [Permission.USERS_INVITE]);
// Carries the permission but is barred at the status level, so the gate must still close.
const SUSPENDED_GRANTER = identity(
  "suspended@erpify.test",
  [Permission.USERS_INVITE, Permission.USERS_GRANT_ADMIN],
  UserStatus.SUSPENDED,
);

/**
 * Reports the hydrated identity. An absent checkbox alone cannot tell "authenticated without the permission"
 * apart from "session never resolved" — both hide it — so the probe pins which one the assertion observes.
 */
function SessionProbe() {
  const { session } = useSession();
  return <span data-testid="probe">{session?.user.email ?? NO_SESSION}</span>;
}

function renderForm() {
  render(
    <AuthProvider>
      <SessionProbe />
      <InviteUserForm />
    </AuthProvider>,
  );
}

function fillValidInvitation() {
  fireEvent.change(screen.getByTestId("invite-user-form__email"), {
    target: { value: "newbie@erpify.test" },
  });
  fireEvent.click(screen.getByTestId(`invite-user-form__role-${Role.EDITOR}`));
}

describe("InviteUserForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.me.mockResolvedValue(GRANTER);
  });

  it("invites through the use case, toasts success and navigates to the list", async () => {
    mocks.inviteRun.mockResolvedValue(undefined);
    renderForm();

    fillValidInvitation();
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    await waitFor(() => {
      expect(mocks.inviteRun).toHaveBeenCalledWith({
        email: "newbie@erpify.test",
        roles: [Role.EDITOR],
      });
    });
    expect(toastNotifier.success).toHaveBeenCalledWith("Invitation sent", {
      description: "newbie@erpify.test",
    });
    expect(mocks.push).toHaveBeenCalled();
  });

  it("maps a 422 email violation onto the email field and does not navigate", async () => {
    mocks.inviteRun.mockRejectedValue(
      new HttpError({
        type: "validation-failed",
        title: "Validation failed.",
        status: HttpStatus.UNPROCESSABLE_ENTITY,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
        violations: [{ field: "email", message: "This email is already in use." }],
      }),
    );
    renderForm();

    fillValidInvitation();
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    expect(await screen.findByText("This email is already in use.")).toBeInTheDocument();
    expect(mocks.push).not.toHaveBeenCalled();
  });

  it("disables the submit button while an invitation is in flight, blocking a second submit", async () => {
    let resolveInvite: () => void = () => {};
    mocks.inviteRun.mockReturnValue(
      new Promise<void>((resolve) => {
        resolveInvite = resolve;
      }),
    );
    renderForm();

    fillValidInvitation();
    const submit = screen.getByTestId("invite-user-form__submit");
    fireEvent.submit(screen.getByTestId("invite-user-form"));

    // The disabled submit is the sole double-submit guard: while the first invitation is in flight the
    // button is disabled, so neither a second click nor Enter can fire another one.
    expect(await screen.findByTestId("invite-user-form__submit-spinner")).toBeInTheDocument();
    expect(submit).toBeDisabled();
    expect(mocks.inviteRun).toHaveBeenCalledTimes(1);

    resolveInvite();
    await waitFor(() => expect(submit).toBeEnabled());
    expect(mocks.inviteRun).toHaveBeenCalledTimes(1);
  });
});

/**
 * Truth table of the ADMIN checkbox. Delegating the administrator role is its own capability, so the row that
 * matters is the one holding `users.invite` without `users.grantAdmin`: every other role stays offered there,
 * which is what separates "the gate closed" from "the roles failed to render".
 */
describe("InviteUserForm ADMIN role gate", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("offers the ADMIN checkbox to a session holding users.grantAdmin", async () => {
    mocks.me.mockResolvedValue(GRANTER);
    renderForm();

    expect(await screen.findByText(GRANTER.email)).toBeInTheDocument();
    expect(screen.getByTestId(ADMIN_CHECKBOX)).toBeInTheDocument();
  });

  it("hides the ADMIN checkbox from an inviter without users.grantAdmin, keeping every other role", async () => {
    mocks.me.mockResolvedValue(INVITER);
    renderForm();

    expect(await screen.findByText(INVITER.email)).toBeInTheDocument();
    expect(screen.queryByTestId(ADMIN_CHECKBOX)).not.toBeInTheDocument();
    for (const role of [Role.VIEWER, Role.EDITOR, Role.MANAGER, Role.AUDIT_READER]) {
      expect(screen.getByTestId(`invite-user-form__role-${role}`)).toBeInTheDocument();
    }
  });

  it("hides the ADMIN checkbox from a suspended holder of the permission", async () => {
    mocks.me.mockResolvedValue(SUSPENDED_GRANTER);
    renderForm();

    expect(await screen.findByText(SUSPENDED_GRANTER.email)).toBeInTheDocument();
    expect(screen.queryByTestId(ADMIN_CHECKBOX)).not.toBeInTheDocument();
  });

  it("hides the ADMIN checkbox when no session resolved", async () => {
    mocks.me.mockResolvedValue(null);
    renderForm();

    expect(await screen.findByText(NO_SESSION)).toBeInTheDocument();
    expect(screen.queryByTestId(ADMIN_CHECKBOX)).not.toBeInTheDocument();
    expect(screen.getByTestId(`invite-user-form__role-${Role.VIEWER}`)).toBeInTheDocument();
  });
});
