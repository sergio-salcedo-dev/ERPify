import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

// The panel and its mint form both resolve the RecoverySecretRepository port from the DI
// container; mock that boundary so the surface drives a controllable fake port (no network).
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

import { RecoverySecretPanel } from "@/app/backoffice/profile/_components/RecoverySecretPanel";

const PASSWORD = "correct-horse-battery";
const MINTED_AT = "2026-08-01T09:00:00.000Z";
const EXPIRES_AT = "2027-08-01T09:00:00.000Z";

function problem(status: number, type: string, detail: string): ProblemDetails {
  return {
    type,
    title: type,
    status,
    detail,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  };
}

/** Opens the confirmation and returns once its password field is on screen. */
async function openRevokeDialog(): Promise<HTMLElement> {
  expect(await screen.findByTestId("recovery-secret__existing")).toBeInTheDocument();
  fireEvent.click(screen.getByTestId("recovery-secret__revoke"));
  return screen.findByTestId("recovery-secret__revoke-password");
}

function typePassword(field: HTMLElement, value: string): void {
  fireEvent.change(field, { target: { value } });
}

beforeEach(() => {
  read.mockReset();
  mint.mockReset();
  revoke.mockReset();
  read.mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
  revoke.mockResolvedValue(undefined);
});

describe("<RecoverySecretPanel> — revoking asks for the current password", () => {
  it("labels the password field, so it is reachable by its name and not only by its test id", async () => {
    render(<RecoverySecretPanel />);
    await openRevokeDialog();

    expect(screen.getByLabelText(/current password/i)).toBe(
      screen.getByTestId("recovery-secret__revoke-password"),
    );
  });

  it("hands the typed password to the port and closes the confirmation", async () => {
    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), PASSWORD);

    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    await waitFor(() => expect(revoke).toHaveBeenCalledWith(PASSWORD));
    await waitFor(() =>
      expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument(),
    );
  });

  it("refuses to reach the port with no password, and keeps the confirmation open to say so", async () => {
    render(<RecoverySecretPanel />);
    await openRevokeDialog();

    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    expect(await screen.findByText("Enter your current password.")).toBeInTheDocument();
    expect(revoke).not.toHaveBeenCalled();
    // A refusal this surface made itself belongs on the field, so the dialog stays put rather
    // than dumping the user back on the panel with a banner about their own empty input.
    expect(screen.getByTestId("recovery-secret__revoke-dialog")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-secret__revoke-error")).not.toBeInTheDocument();
  });

  it("surfaces a rejected password on the panel's persistent banner, secret intact", async () => {
    revoke.mockRejectedValue(
      new HttpError(
        problem(
          HttpStatus.FORBIDDEN,
          "invalid-current-password",
          "The current password is not correct.",
        ),
      ),
    );

    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), "wrong-password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));

    const banner = await screen.findByTestId("recovery-secret__revoke-error");
    expect(banner).toHaveTextContent("The current password is not correct.");
    expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument();
    // The secret survives a refused revoke: the panel must still offer the revoke rather than
    // flipping to the mint form as though the credential were gone.
    expect(screen.getByTestId("recovery-secret__existing")).toBeInTheDocument();
  });

  it("starts the next attempt empty, so a refused credential does not sit in the field", async () => {
    revoke.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.TOO_MANY_REQUESTS, "rate-limited", "Too many attempts. Try later."),
      ),
    );

    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), "wrong-password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-confirm"));
    await screen.findByTestId("recovery-secret__revoke-error");

    expect(await openRevokeDialog()).toHaveValue("");
  });

  /**
   * A `<button>` inside a form submits it unless it says otherwise, so the cancel control is one
   * missing attribute away from being a second confirm — on the dialog guarding the account's
   * last way back in.
   */
  it("cancels without revoking, and drops the credential the user had typed", async () => {
    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), PASSWORD);

    fireEvent.click(screen.getByRole("button", { name: "Keep the recovery secret" }));

    await waitFor(() =>
      expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument(),
    );
    expect(revoke).not.toHaveBeenCalled();
    expect(await openRevokeDialog()).toHaveValue("");
  });

  /**
   * The owner's `revoking` flag is raised only once the async Zod resolver has settled and the
   * port has been reached, so two presses inside that window both arrive at
   * `POST /me/recovery-secret/revoke`. Each spends a unit of the shared per-identity
   * credential-proof bucket, which is the only ceiling on guessing this password.
   */
  it("reaches the port once however fast the confirmation is pressed twice", async () => {
    let settle: () => void = () => {};
    revoke.mockImplementationOnce(
      () =>
        new Promise<void>((resolve) => {
          settle = () => resolve();
        }),
    );

    render(<RecoverySecretPanel />);
    typePassword(await openRevokeDialog(), PASSWORD);

    const confirm = screen.getByTestId("recovery-secret__revoke-confirm");
    fireEvent.click(confirm);
    fireEvent.click(confirm);

    await waitFor(() => expect(revoke).toHaveBeenCalledTimes(1));
    settle();
    await waitFor(() =>
      expect(screen.queryByTestId("recovery-secret__revoke-dialog")).not.toBeInTheDocument(),
    );
    expect(revoke).toHaveBeenCalledTimes(1);
  });

  it("keeps the typed password masked until the reveal toggle is pressed", async () => {
    render(<RecoverySecretPanel />);
    const field = await openRevokeDialog();

    expect(field).toHaveAttribute("type", "password");
    fireEvent.click(screen.getByTestId("recovery-secret__revoke-password-toggle"));
    expect(screen.getByTestId("recovery-secret__revoke-password")).toHaveAttribute("type", "text");
  });
});

/**
 * The expiry is the one way this channel closes with nobody acting, so a holder who is never
 * shown the date cannot plan around it — which makes the two instants part of what this panel
 * owes, not decoration. Both are asserted by their year rather than by a formatted string: the
 * suite pins no timezone, and each instant sits mid-year, so no real offset moves either.
 */
/**
 * A 409 means the account already holds a secret and this surface's read is simply behind. The
 * panel answers by re-reading, which swaps the mint form for the revoke-then-mint view the API
 * is describing — without it the owner is left pressing a button that can never succeed, which
 * is the outcome the branch exists to prevent.
 */
describe("<RecoverySecretPanel> — a mint refused because one already exists", () => {
  const alreadyExists = problem(
    HttpStatus.CONFLICT,
    "recovery-secret-already-exists",
    "This account already holds a recovery secret.",
  );

  it("re-reads, so the surface stops offering a mint the API will keep refusing", async () => {
    read
      .mockResolvedValueOnce({ exists: false, mintedAt: null, expiresAt: null })
      .mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
    mint.mockRejectedValue(new HttpError(alreadyExists));

    render(<RecoverySecretPanel />);
    fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
      target: { value: PASSWORD },
    });
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    expect(await screen.findByTestId("recovery-secret__existing")).toBeInTheDocument();
    expect(screen.queryByTestId("mint-recovery-secret")).not.toBeInTheDocument();
    // Two reads, not one: the mount's and the one the refusal forced.
    expect(read).toHaveBeenCalledTimes(2);
  });

  it("keeps the refusal on screen, so the swap is explained rather than silent", async () => {
    read
      .mockResolvedValueOnce({ exists: false, mintedAt: null, expiresAt: null })
      .mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });
    mint.mockRejectedValue(new HttpError(alreadyExists));

    render(<RecoverySecretPanel />);
    fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
      target: { value: PASSWORD },
    });
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    expect(
      await screen.findByText("This account already holds a recovery secret."),
    ).toBeInTheDocument();
  });

  it("does not re-read for a refusal that says nothing about what the account holds", async () => {
    read.mockResolvedValue({ exists: false, mintedAt: null, expiresAt: null });
    mint.mockRejectedValue(
      new HttpError(
        problem(HttpStatus.TOO_MANY_REQUESTS, "rate-limited", "Too many attempts. Try later."),
      ),
    );

    render(<RecoverySecretPanel />);
    fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
      target: { value: PASSWORD },
    });
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    expect(await screen.findByText("Too many attempts. Try later.")).toBeInTheDocument();
    // The mount's read and no other: re-reading on every refusal would spend a request per
    // wrong password, and the state it would re-read has not changed.
    expect(read).toHaveBeenCalledTimes(1);
  });
});

/**
 * Expiry does not delete the row and no sweep does either — the feature file seeds exactly that
 * state to prove the redemption refuses it without consuming it. So the read keeps answering
 * `exists: true` for a credential that no longer signs anybody in, and a fixed `Active` badge
 * tells the one person who depends on this channel that they hold a way back in.
 */
describe("<RecoverySecretPanel> — a secret that has already lapsed", () => {
  const LAPSED_AT = "2020-01-01T00:00:00.000Z";

  it("says Expired rather than Active, and names the only way to replace it", async () => {
    read.mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: LAPSED_AT });

    render(<RecoverySecretPanel />);

    expect(await screen.findByTestId("recovery-secret__state")).toHaveTextContent("Expired");
    expect(screen.getByText(/expired and will no longer sign you in/i)).toBeInTheDocument();
  });

  it("still says Active while the expiry is ahead, so the badge is derived and not inverted", async () => {
    // The other direction, and the half a one-sided assertion would leave open: a `lapsed` that
    // was always true would satisfy the case above while labelling every live secret Expired.
    read.mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });

    render(<RecoverySecretPanel />);

    expect(await screen.findByTestId("recovery-secret__state")).toHaveTextContent("Active");
  });

  it("does not claim Expired from an instant it could not read", async () => {
    // The row is the API's to judge. Reading "Expired" out of an unparseable date would be the
    // same false confidence pointed the other way, on the surface that decides whether the owner
    // thinks they still have a way in.
    read.mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: "not-a-date" });

    render(<RecoverySecretPanel />);

    expect(await screen.findByTestId("recovery-secret__state")).toHaveTextContent("Active");
  });

  it("keeps offering the revoke, which is the action that clears the way to a new one", async () => {
    read.mockResolvedValue({ exists: true, mintedAt: MINTED_AT, expiresAt: LAPSED_AT });

    render(<RecoverySecretPanel />);

    expect(await screen.findByTestId("recovery-secret__revoke")).toBeInTheDocument();
  });
});

describe("<RecoverySecretPanel> — the instants that bound the secret's life", () => {
  it("renders both instants beside a secret the account already holds", async () => {
    render(<RecoverySecretPanel />);

    const mintedAt = await screen.findByTestId("recovery-secret__minted-at");
    const expiresAt = screen.getByTestId("recovery-secret__expires-at");

    expect(mintedAt).toHaveTextContent("2026");
    expect(expiresAt).toHaveTextContent("2027");
    // Two slots, two values: a panel feeding one instant into both reads as correct right up
    // to the day the expiry is the thing being read.
    expect(mintedAt.textContent).not.toBe(expiresAt.textContent);
  });

  it("renders both instants beside the once-only reveal, where the secret is first handed over", async () => {
    read.mockResolvedValue({ exists: false, mintedAt: null, expiresAt: null });
    mint.mockResolvedValue({ secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT });

    render(<RecoverySecretPanel />);
    fireEvent.change(await screen.findByTestId("mint-recovery-secret__current-password"), {
      target: { value: PASSWORD },
    });
    fireEvent.click(screen.getByTestId("mint-recovery-secret__submit"));

    expect(await screen.findByTestId("recovery-secret__minted")).toBeInTheDocument();
    expect(screen.getByTestId("recovery-secret__minted-at")).toHaveTextContent("2026");
    expect(screen.getByTestId("recovery-secret__expires-at")).toHaveTextContent("2027");
  });
});
