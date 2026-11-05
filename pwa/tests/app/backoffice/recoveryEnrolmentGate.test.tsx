import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useState, type ReactNode } from "react";

const { auth, repo } = vi.hoisted(() => ({
  auth: { session: null as Session | null },
  repo: { read: vi.fn(), mint: vi.fn(), revoke: vi.fn() },
}));

vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({
    session: auth.session,
    status: "authenticated",
    login: vi.fn(),
    logout: vi.fn(),
    override: vi.fn(),
  }),
}));
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => repo },
}));

import { RecoveryEnrolmentGate } from "@/app/backoffice/_components/RecoveryEnrolmentGate";
import {
  RecoveryReadinessContext,
  RecoveryReadinessProvider,
} from "@/context/shared/access/infrastructure/ui/RecoveryReadinessProvider";
import {
  recoveryReadinessOf,
  type RecoveryReadiness,
} from "@/context/shared/access/domain/RecoveryReadiness";
import { AccessContext } from "@/context/shared/access/domain/AccessContext";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Session } from "@/context/shared/access/domain/Session";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import {
  clearRecoveryRedeemed,
  clearRecoveryRedeemedSession,
  markRecoveryRedeemed,
} from "@/context/backoffice/user/application/recoveryRedeemedNotice";

const PASSWORD = "correct-horse-battery";
const SECRET = "erpify-rs-0123456789abcdef";
const MINTED_AT = "2026-08-01T09:00:00.000Z";
const EXPIRES_AT = "2036-08-01T09:00:00.000Z";
const NONE = { exists: false, live: false, mintedAt: null, expiresAt: null };

function sessionWith(roles: Role[], status: UserStatus = UserStatus.ACTIVE): Session {
  return {
    user: {
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
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

/** A shell whose verdict the test sets and a mutation can change, as the real provider does. */
function Shell({
  initial,
  children,
}: Readonly<{ initial: RecoveryReadiness; children: ReactNode }>) {
  const [readiness, setReadiness] = useState(initial);
  return (
    <RecoveryReadinessContext.Provider
      value={{ readiness, publish: (status) => setReadiness(recoveryReadinessOf(status)) }}
    >
      {children}
    </RecoveryReadinessContext.Provider>
  );
}

function Page() {
  return <p data-testid="page">Section</p>;
}

function renderGate(initial: RecoveryReadiness, onSignOut = vi.fn(), signingOut = false) {
  render(
    <Shell initial={initial}>
      <RecoveryEnrolmentGate onSignOut={onSignOut} signingOut={signingOut}>
        <Page />
      </RecoveryEnrolmentGate>
    </Shell>,
  );
  return { onSignOut };
}

const NOT_LIVE_NONE: RecoveryReadiness = { kind: "not-live", reason: "none" };

function problem(status: number, type: string, detail: string) {
  return new HttpError({
    type,
    title: type,
    status,
    detail,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  });
}

async function mintThroughTheStep(): Promise<void> {
  fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
    target: { value: PASSWORD },
  });
  fireEvent.click(screen.getByTestId("mint-recovery-secret__submit"));
}

beforeEach(() => {
  repo.read.mockReset();
  repo.mint.mockReset();
  repo.read.mockResolvedValue(NONE);
  repo.mint.mockResolvedValue({ secret: SECRET, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
  auth.session = sessionWith(["ADMIN"]);
  clearRecoveryRedeemed();
  clearRecoveryRedeemedSession();
});

afterEach(() => {
  clearRecoveryRedeemed();
  clearRecoveryRedeemedSession();
});

/**
 * An administrator with no recovery secret at all cannot use the back office until they mint one. It blocks on
 * exactly one verdict — a KNOWN `not-live` with reason `none` — so each of the other states below is a case
 * that must stay open, not a happy path.
 */
describe("<RecoveryEnrolmentGate> — what it blocks", () => {
  it("replaces the page with the enrolment step for an administrator holding no secret", async () => {
    renderGate(NOT_LIVE_NONE);

    expect(await screen.findByTestId("recovery-enrolment")).toBeInTheDocument();
    expect(screen.queryByTestId("page")).not.toBeInTheDocument();
    expect(screen.getByTestId("mint-recovery-secret")).toBeInTheDocument();
  });

  it("takes the focus, so the page being replaced is not a silent change", async () => {
    renderGate(NOT_LIVE_NONE);

    expect(await screen.findByTestId("recovery-enrolment__title")).toHaveFocus();
  });

  it("offers a way to sign out and no way to skip", async () => {
    const { onSignOut } = renderGate(NOT_LIVE_NONE);

    fireEvent.click(await screen.findByTestId("recovery-enrolment__signout"));
    expect(onSignOut).toHaveBeenCalledTimes(1);

    const step = screen.getByTestId("recovery-enrolment");
    const labels = Array.from(step.querySelectorAll("button, a")).map(
      (control) => control.getAttribute("aria-label") ?? control.textContent,
    );
    expect(labels).toEqual(["Show/hide password", "Create recovery secret", "Sign out"]);
    expect(step.querySelector("a")).toBeNull();
  });

  it("says why, and that creating the secret needs the current password", async () => {
    renderGate(NOT_LIVE_NONE);

    const step = await screen.findByTestId("recovery-enrolment");
    expect(step).toHaveTextContent("Your account has no recovery secret.");
    expect(step).toHaveTextContent("Creating it asks for your current password.");
  });

  it("holds the sign-out control while a sign-out is in flight", async () => {
    renderGate(NOT_LIVE_NONE, vi.fn(), true);

    const signOut = await screen.findByTestId("recovery-enrolment__signout");
    expect(signOut).toBeDisabled();
    expect(signOut).toHaveTextContent("Signing out…");
  });
});

describe("<RecoveryEnrolmentGate> — what it leaves open", () => {
  it("does not block while the verdict is unknown (a read in flight or one that failed)", () => {
    renderGate({ kind: "unknown" });

    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("does not block a lapsed secret: the row has to be revoked first, on the profile", () => {
    renderGate({ kind: "not-live", reason: "expired" });

    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("does not block an administrator whose secret is live", () => {
    renderGate({ kind: "live" });

    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("never blocks a session without ADMIN, whatever the verdict says", () => {
    auth.session = sessionWith(["MANAGER", "AUDIT_READER"]);
    renderGate(NOT_LIVE_NONE);

    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("never blocks a session that is not ACTIVE", () => {
    auth.session = sessionWith(["ADMIN"], UserStatus.SUSPENDED);
    renderGate(NOT_LIVE_NONE);

    expect(screen.getByTestId("page")).toBeInTheDocument();
  });

  it("does not block a session opened by redeeming a recovery secret, which may not know the password", () => {
    markRecoveryRedeemed();
    // The dashboard clears the one-shot notice on first paint; the session fact has to outlive it.
    clearRecoveryRedeemed();
    renderGate(NOT_LIVE_NONE);

    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("blocks again once a password sign-in has cleared the redemption", async () => {
    markRecoveryRedeemed();
    clearRecoveryRedeemedSession();
    renderGate(NOT_LIVE_NONE);

    expect(await screen.findByTestId("recovery-enrolment")).toBeInTheDocument();
  });
});

describe("<RecoveryEnrolmentGate> — minting", () => {
  it("reveals the secret once, then opens the page on acknowledgement", async () => {
    renderGate(NOT_LIVE_NONE);

    await mintThroughTheStep();

    // The verdict flipped to live the moment the mint succeeded, and the reveal still holds the page
    // back: unmounting it now would discard the only copy of a credential shown exactly once.
    expect(await screen.findByTestId("recovery-secret__value")).toHaveTextContent(SECRET);
    expect(screen.queryByTestId("page")).not.toBeInTheDocument();
    expect(screen.queryByTestId("mint-recovery-secret")).not.toBeInTheDocument();
    expect(repo.mint).toHaveBeenCalledWith(PASSWORD);

    fireEvent.click(screen.getByTestId("recovery-secret__acknowledge"));

    expect(await screen.findByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
    expect(screen.queryByText(SECRET)).not.toBeInTheDocument();
  });

  it("keeps the step open and answers a wrong password on the field", async () => {
    repo.mint.mockRejectedValue(
      problem(HttpStatus.FORBIDDEN, "invalid-current-password", "The current password is wrong."),
    );
    renderGate(NOT_LIVE_NONE);

    await mintThroughTheStep();

    expect(await screen.findByText("The current password is wrong.")).toBeInTheDocument();
    expect(screen.getByTestId("recovery-enrolment")).toBeInTheDocument();
    expect(screen.queryByTestId("page")).not.toBeInTheDocument();
  });

  it("keeps the step open and shows a spent proof budget on its persistent surface", async () => {
    repo.mint.mockRejectedValue(
      problem(HttpStatus.TOO_MANY_REQUESTS, "too-many-requests", "Too many attempts."),
    );
    renderGate(NOT_LIVE_NONE);

    await mintThroughTheStep();

    expect(await screen.findByTestId("recovery-enrolment__mint-error")).toBeInTheDocument();
    expect(screen.queryByTestId("page")).not.toBeInTheDocument();
  });

  it("stands down when the account turns out to hold a secret already", async () => {
    repo.mint.mockRejectedValue(
      problem(
        HttpStatus.CONFLICT,
        "recovery-secret-already-exists",
        "This account already holds a recovery secret.",
      ),
    );
    renderGate(NOT_LIVE_NONE);
    await waitFor(() => expect(repo.read).toHaveBeenCalledTimes(1));
    repo.read.mockResolvedValue({
      exists: true,
      live: true,
      mintedAt: MINTED_AT,
      expiresAt: EXPIRES_AT,
    });

    await mintThroughTheStep();

    expect(await screen.findByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });
});

describe("<RecoveryEnrolmentGate> — with the shell's own provider", () => {
  it("reads the verdict, blocks on `none`, and stands down on the mint's own answer", async () => {
    render(
      <RecoveryReadinessProvider>
        <RecoveryEnrolmentGate onSignOut={vi.fn()} signingOut={false}>
          <Page />
        </RecoveryEnrolmentGate>
      </RecoveryReadinessProvider>,
    );

    // Unknown while the one read is in flight: the page is not held back on "we could not tell".
    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(await screen.findByTestId("recovery-enrolment")).toBeInTheDocument();

    await mintThroughTheStep();
    fireEvent.click(await screen.findByTestId("recovery-secret__acknowledge"));

    expect(await screen.findByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });

  it("never blocks when the read fails", async () => {
    repo.read.mockRejectedValue(new Error("network down"));
    render(
      <RecoveryReadinessProvider>
        <RecoveryEnrolmentGate onSignOut={vi.fn()} signingOut={false}>
          <Page />
        </RecoveryEnrolmentGate>
      </RecoveryReadinessProvider>,
    );

    await waitFor(() => expect(repo.read).toHaveBeenCalled());
    expect(screen.getByTestId("page")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-enrolment")).not.toBeInTheDocument();
  });
});
