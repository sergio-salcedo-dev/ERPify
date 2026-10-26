import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { Session } from "@/context/shared/access/domain/Session";

const { auth } = vi.hoisted(() => ({ auth: { session: null as Session | null } }));

const read = vi.fn();
const mint = vi.fn();
const revoke = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      read: (...args: unknown[]) => read(...args),
      mint: (...args: unknown[]) => mint(...args),
      revoke: (...args: unknown[]) => revoke(...args),
    }),
  },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ session: auth.session }),
}));

import { RecoveryReadinessProvider } from "@/context/shared/access/infrastructure/ui/RecoveryReadinessProvider";
import { useRecoveryReadiness } from "@/context/shared/access/application/useRecoveryReadiness";
import { RecoverySecretPanel } from "@/app/backoffice/profile/_components/RecoverySecretPanel";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";

const MINTED_AT = "2026-08-01T09:00:00.000Z";
const EXPIRES_AT = "2036-08-01T09:00:00.000Z";
const NONE = { exists: false, live: false, mintedAt: null, expiresAt: null };
const LIVE = { exists: true, live: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT };

const OTHER_ADMIN_ID = "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5e";

function sessionWith(
  roles: Role[],
  status: UserStatus = UserStatus.ACTIVE,
  id = "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
): Session {
  return {
    user: {
      id,
      email: "ada@erpify.test",
      status,
      roles,
      permissions: [],
    },
    roles,
    permissions: [],
    context: AccessContext.BACKOFFICE,
  };
}

function Verdict() {
  const { readiness } = useRecoveryReadiness();
  return (
    <p data-testid="verdict">
      {readiness.kind === "not-live" ? `not-live:${readiness.reason}` : readiness.kind}
    </p>
  );
}

function verdict(): string | null {
  return screen.getByTestId("verdict").textContent;
}

beforeEach(() => {
  read.mockReset();
  mint.mockReset();
  revoke.mockReset();
  auth.session = sessionWith(["ADMIN"]);
});

describe("RecoveryReadinessProvider — the shell's read", () => {
  it("reads once for an active administrator and holds the server's verdict", async () => {
    read.mockResolvedValue(NONE);
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );

    await waitFor(() => expect(verdict()).toBe("not-live:none"));
    expect(read).toHaveBeenCalledTimes(1);
  });

  it("tells a lapsed secret from no secret, because the way out differs", async () => {
    read.mockResolvedValue({ ...LIVE, live: false });
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );

    await waitFor(() => expect(verdict()).toBe("not-live:expired"));
  });

  it("is unknown — never not-live — while the read is in flight", () => {
    read.mockReturnValue(new Promise(() => {}));
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );

    expect(verdict()).toBe("unknown");
  });

  it("stays unknown when the read fails", async () => {
    read.mockRejectedValue(new Error("network"));
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );

    await waitFor(() => expect(read).toHaveBeenCalledTimes(1));
    await act(async () => {});
    expect(verdict()).toBe("unknown");
  });

  it("keeps a live verdict when the secret lapses with the page open — a snapshot, never a poll", async () => {
    read.mockResolvedValue({ ...LIVE, expiresAt: "2026-08-01T10:00:00.000Z" });
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );
    await waitFor(() => expect(verdict()).toBe("live"));

    vi.useFakeTimers();
    try {
      vi.setSystemTime(new Date("2026-08-02T10:00:00.000Z"));
      await act(async () => {
        vi.advanceTimersByTime(24 * 60 * 60 * 1000);
      });

      expect(verdict()).toBe("live");
      expect(read).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it.each([
    { label: "a session without ADMIN", session: () => sessionWith(["MANAGER"]) },
    { label: "no session", session: () => null },
    {
      label: "an administrator who is not ACTIVE",
      session: () => sessionWith(["ADMIN"], UserStatus.SUSPENDED),
    },
  ])("asks nothing for $label", ({ session }) => {
    auth.session = session();
    render(
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>,
    );

    expect(read).not.toHaveBeenCalled();
    expect(verdict()).toBe("unknown");
  });
});

describe("RecoveryReadinessProvider — mutations made elsewhere in the shell", () => {
  async function mintThroughThePanel(): Promise<void> {
    fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
      target: { value: "correct-horse-battery" },
    });
    fireEvent.click(screen.getByTestId("mint-recovery-secret__submit"));
    await screen.findByTestId("recovery-secret__minted");
  }

  it("turns live on a mint, from the 201 alone and with no second GET", async () => {
    read.mockResolvedValue(NONE);
    mint.mockResolvedValue({ secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
    render(
      <RecoveryReadinessProvider>
        <Verdict />
        <RecoverySecretPanel />
      </RecoveryReadinessProvider>,
    );
    await waitFor(() => expect(verdict()).toBe("not-live:none"));
    const readsBeforeMint = read.mock.calls.length;

    await mintThroughThePanel();

    expect(verdict()).toBe("live");
    expect(read).toHaveBeenCalledTimes(readsBeforeMint);
  });

  it("turns not-live on a revoke, from the 204 alone and with no second GET", async () => {
    read.mockResolvedValue(LIVE);
    revoke.mockResolvedValue(undefined);
    render(
      <RecoveryReadinessProvider>
        <Verdict />
        <RecoverySecretPanel />
      </RecoveryReadinessProvider>,
    );
    await waitFor(() => expect(verdict()).toBe("live"));
    const readsBeforeRevoke = read.mock.calls.length;

    fireEvent.click(await screen.findByTestId("recovery-secret__revoke"));
    fireEvent.change(await screen.findByTestId("recovery-secret__revoke-password"), {
      target: { value: "correct-horse-battery" },
    });
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    await waitFor(() => expect(verdict()).toBe("not-live:none"));
    expect(read).toHaveBeenCalledTimes(readsBeforeRevoke);
  });

  it("does not let a slow shell read overwrite what a later mint established", async () => {
    let answerShell: (status: unknown) => void = () => {};
    // React runs a child's effects before its parent's, so the panel's read is the first call and
    // the shell's is the second: only the shell's is held open.
    read.mockResolvedValueOnce(NONE).mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          answerShell = resolve;
        }),
    );
    mint.mockResolvedValue({ secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
    render(
      <RecoveryReadinessProvider>
        <Verdict />
        <RecoverySecretPanel />
      </RecoveryReadinessProvider>,
    );

    await mintThroughThePanel();
    expect(verdict()).toBe("live");

    await act(async () => {
      answerShell(NONE);
    });
    expect(verdict()).toBe("live");
  });
});

describe("RecoveryReadinessProvider — whose verdict it holds", () => {
  function held(): { promise: Promise<unknown>; answer: (status: unknown) => void } {
    let answer: (status: unknown) => void = () => {};
    const promise = new Promise((resolve) => {
      answer = resolve;
    });
    return { promise, answer };
  }

  function tree() {
    return (
      <RecoveryReadinessProvider>
        <Verdict />
      </RecoveryReadinessProvider>
    );
  }

  it("takes the verdict from the panel's own read when the shell's read fails", async () => {
    // React runs a child's effects before its parent's: the panel's read is the first call.
    read.mockResolvedValueOnce(NONE).mockRejectedValueOnce(new Error("network"));
    render(
      <RecoveryReadinessProvider>
        <Verdict />
        <RecoverySecretPanel />
      </RecoveryReadinessProvider>,
    );

    await waitFor(() => expect(verdict()).toBe("not-live:none"));
    expect(read).toHaveBeenCalledTimes(2);
  });

  it("ignores what the panel learns for a session outside the audience", async () => {
    auth.session = sessionWith(["MANAGER"]);
    read.mockResolvedValue(NONE);
    render(
      <RecoveryReadinessProvider>
        <Verdict />
        <RecoverySecretPanel />
      </RecoveryReadinessProvider>,
    );

    await screen.findByTestId("recovery-secret__empty");
    expect(verdict()).toBe("unknown");
  });

  it("forgets the previous administrator's verdict when another one takes the shell", async () => {
    const second = held();
    read.mockResolvedValueOnce(NONE).mockReturnValueOnce(second.promise);
    const { rerender } = render(tree());
    await waitFor(() => expect(verdict()).toBe("not-live:none"));

    auth.session = sessionWith(["ADMIN"], UserStatus.ACTIVE, OTHER_ADMIN_ID);
    rerender(tree());

    await waitFor(() => expect(read).toHaveBeenCalledTimes(2));
    expect(verdict()).toBe("unknown");
    await act(async () => {
      second.answer(LIVE);
    });
    expect(verdict()).toBe("live");
  });

  it("drops the previous administrator's late answer", async () => {
    const first = held();
    const second = held();
    read.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const { rerender } = render(tree());

    auth.session = sessionWith(["ADMIN"], UserStatus.ACTIVE, OTHER_ADMIN_ID);
    rerender(tree());
    await waitFor(() => expect(read).toHaveBeenCalledTimes(2));

    await act(async () => {
      first.answer(NONE);
    });
    expect(verdict()).toBe("unknown");
  });
});
