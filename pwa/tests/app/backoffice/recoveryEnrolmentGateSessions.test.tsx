import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";

const { repo } = vi.hoisted(() => ({
  repo: { me: vi.fn(), revokeCurrent: vi.fn(), read: vi.fn(), mint: vi.fn() },
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => repo },
}));
vi.mock("@/context/shared/observability/infrastructure", () => ({
  telemetry: { warn: vi.fn(), error: vi.fn() },
}));
vi.mock("next/navigation", () => ({ usePathname: () => "/backoffice" }));

import { RecoveryEnrolmentGate } from "@/app/backoffice/_components/RecoveryEnrolmentGate";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { RecoveryReadinessProvider } from "@/context/shared/access/infrastructure/ui/RecoveryReadinessProvider";
import { useSession } from "@/context/shared/access/application/useSession";
import {
  clearSessionRedeemed,
  markSessionRedeemed,
} from "@/context/shared/access/infrastructure/redeemedSession";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Identity } from "@/context/shared/access/domain/Identity";

const ADMIN: Identity = {
  id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
  email: "ada@erpify.test",
  status: UserStatus.ACTIVE,
  roles: ["ADMIN"],
  permissions: [],
};

/**
 * Stands in for every route that opens or ends a session: the recovery redemption (which marks the session
 * AFTER its own `login()`), and the password, reset-password and invitation-accept sign-ins (which only call
 * `login()`).
 */
function Routes() {
  const { login, logout } = useSession();
  return (
    <>
      <button
        type="button"
        data-testid="redeem"
        onClick={() => {
          void login().then(markSessionRedeemed);
        }}
      />
      <button type="button" data-testid="sign-in" onClick={() => void login()} />
      <button type="button" data-testid="sign-out" onClick={() => void logout()} />
    </>
  );
}

function renderBackOffice() {
  render(
    <AuthProvider>
      <RecoveryReadinessProvider>
        <Routes />
        <RecoveryEnrolmentGate onSignOut={vi.fn()} signingOut={false}>
          <p data-testid="page">Section</p>
        </RecoveryEnrolmentGate>
      </RecoveryReadinessProvider>
    </AuthProvider>,
  );
}

const wall = () => screen.queryByTestId("recovery-enrolment");

beforeEach(() => {
  clearSessionRedeemed();
  repo.me.mockReset();
  repo.me.mockResolvedValue(null);
  repo.revokeCurrent.mockReset();
  repo.revokeCurrent.mockResolvedValue(undefined);
  repo.read.mockReset();
  repo.read.mockResolvedValue({ exists: false, live: false, mintedAt: null, expiresAt: null });
});

describe("the enrolment wall across the sessions of one document", () => {
  it("lets a redeemed session through without a secret", async () => {
    renderBackOffice();
    repo.me.mockResolvedValue(ADMIN);

    fireEvent.click(screen.getByTestId("redeem"));

    await waitFor(() => expect(repo.read).toHaveBeenCalled());
    expect(await screen.findByTestId("page")).toBeInTheDocument();
    expect(wall()).toBeNull();
  });

  it("walls the password sign-in that follows a redemption and a sign-out", async () => {
    renderBackOffice();
    repo.me.mockResolvedValue(ADMIN);
    fireEvent.click(screen.getByTestId("redeem"));
    await waitFor(() => expect(repo.read).toHaveBeenCalledTimes(1));

    repo.me.mockResolvedValue(null);
    fireEvent.click(screen.getByTestId("sign-out"));
    await waitFor(() => expect(repo.revokeCurrent).toHaveBeenCalled());
    // Let the sign-out settle before the next sign-in starts: a probe begun mid-sign-out is superseded.
    await act(async () => {});
    // Signed out: no verdict is known for nobody, so the page is not held back.
    expect(await screen.findByTestId("page")).toBeInTheDocument();

    repo.me.mockResolvedValue(ADMIN);
    fireEvent.click(screen.getByTestId("sign-in"));

    expect(await screen.findByTestId("recovery-enrolment")).toBeInTheDocument();
    expect(screen.queryByTestId("page")).not.toBeInTheDocument();
  });

  it.each(["reset-password", "invitation-accept"])(
    "walls the %s sign-in in a document that earlier redeemed a secret",
    async () => {
      renderBackOffice();
      // The earlier redemption's session has ended without a sign-out reaching this provider.
      markSessionRedeemed();
      repo.me.mockResolvedValue(ADMIN);

      fireEvent.click(screen.getByTestId("sign-in"));

      expect(await screen.findByTestId("recovery-enrolment")).toBeInTheDocument();
      expect(screen.queryByTestId("page")).not.toBeInTheDocument();
    },
  );
});
