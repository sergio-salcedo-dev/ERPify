import { describe, expect, it, vi, beforeEach } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { LoginOutcomeKind, type LoginOutcome } from "@/context/backoffice/user/domain/LoginOutcome";
import type { Identity } from "@/context/shared/access/domain/Identity";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

/**
 * The form against the REAL session provider, where `login()` can be superseded by a probe
 * started after it. A mocked `useSession` cannot reach that path: it is the provider's
 * sequencing, and what the form makes of it, that this file pins.
 */
const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: (...args: unknown[]) => push(...args), replace: vi.fn() }),
  usePathname: () => "/login",
}));

const me = vi.fn();
const repoLogin = vi.fn(async (): Promise<LoginOutcome> => ({
  kind: LoginOutcomeKind.AUTHENTICATED,
}));
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      me: (...args: unknown[]) => me(...args),
      revokeCurrent: vi.fn(),
      login: () => repoLogin(),
    }),
  },
}));
vi.mock("@/context/shared/observability/infrastructure", () => ({
  telemetry: { warn: vi.fn(), error: vi.fn() },
}));

import { LoginForm } from "@/app/(auth)/_components/LoginForm";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { useSession } from "@/context/shared/access/application/useSession";

const ADA: Identity = {
  id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
  email: "ada@erpify.test",
  status: UserStatus.ACTIVE,
  roles: ["ADMIN"],
  permissions: [],
};

function pending(): { promise: Promise<Identity | null>; settle: (i: Identity | null) => void } {
  let settle!: (identity: Identity | null) => void;
  const promise = new Promise<Identity | null>((resolve) => {
    settle = resolve;
  });
  return { promise, settle };
}

/** Starts a probe from beside the form, the way the navbar's retry does. */
function LaterProbe() {
  const { refresh } = useSession();
  return (
    <button type="button" data-testid="test__later-probe" onClick={() => void refresh()}>
      probe
    </button>
  );
}

beforeEach(() => {
  push.mockClear();
  me.mockReset();
  window.history.replaceState({}, "", "/login");
});

describe("LoginForm — a sign-in whose probe a later one superseded", () => {
  it("shows no failure and completes the sign-in when the later probe finds the session", async () => {
    const own = pending();
    const later = pending();
    me.mockResolvedValueOnce(null)
      .mockReturnValueOnce(own.promise)
      .mockReturnValueOnce(later.promise);

    render(
      <AuthProvider>
        <LoginForm />
        <LaterProbe />
      </AuthProvider>,
    );
    await waitFor(() => expect(me).toHaveBeenCalledTimes(1));

    fireEvent.change(screen.getByTestId("login-form__email"), { target: { value: "a@b.com" } });
    fireEvent.change(screen.getByTestId("login-form__password"), {
      target: { value: "secret123" },
    });
    fireEvent.click(screen.getByTestId("login-form__submit"));
    await waitFor(() => expect(me).toHaveBeenCalledTimes(2));

    fireEvent.click(screen.getByTestId("test__later-probe"));
    expect(me).toHaveBeenCalledTimes(3);

    // The superseded probe answers first, and with nothing: its answer is not the form's to read.
    await act(async () => {
      own.settle(null);
    });
    expect(screen.queryByTestId("login-form__request-error")).toBeNull();

    await act(async () => {
      later.settle(ADA);
    });

    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice"));
    expect(screen.queryByTestId("login-form__request-error")).toBeNull();
  });
});
