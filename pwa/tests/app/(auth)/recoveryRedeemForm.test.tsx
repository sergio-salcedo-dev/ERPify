import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import {
  RedeemRecoverySecretOutcomeKind,
  type RedeemRecoverySecretOutcome,
} from "@/context/backoffice/user/domain/RedeemRecoverySecretOutcome";
import { SIGNED_IN_SESSION } from "./_session";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({
    push: (...args: unknown[]) => push(...args),
    replace: vi.fn(),
    refresh: vi.fn(),
  }),
}));

const login = vi.fn();
vi.mock("@/context/shared/access/application/useSession", () => ({
  useSession: () => ({ login: (...args: unknown[]) => login(...args), status: "unauthenticated" }),
}));

let online = true;
vi.mock("@/context/shared/connectivity/infrastructure/useOnlineStatus", () => ({
  useOnlineStatus: () => online,
}));

// The success toast is the form's only positive announcement, so it is the thing a false
// success would be observed through — it is mocked to be asserted, not merely to be silenced.
const toastSuccess = vi.fn();
vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: {
    success: (...args: unknown[]) => toastSuccess(...args),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
  },
}));

// The redeem port outcome is driven per-test via `outcome`; the form resolves the repository
// from the (mocked) DI container.
let outcome: RedeemRecoverySecretOutcome = { kind: RedeemRecoverySecretOutcomeKind.REDEEMED };
const repoRedeem = vi.fn(async (_secret: string): Promise<RedeemRecoverySecretOutcome> => outcome);
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ redeem: (secret: string) => repoRedeem(secret) }) },
}));

import { RecoveryRedeemForm } from "@/app/(auth)/_components/RecoveryRedeemForm";
import RecoveryPage, { metadata } from "@/app/(auth)/recovery/page";

const SECRET = "0190b1c2d3e47f5a8b6c1d2e3f4a5b60.super-secret-verifier";
const INVALID_SECRET_MESSAGE =
  "That recovery secret did not work. Check you copied all of it, then try again.";

function typeSecret(value = SECRET): void {
  fireEvent.change(screen.getByTestId("recovery-redeem-form__secret"), { target: { value } });
}

function submit(value = SECRET): void {
  typeSecret(value);
  fireEvent.click(screen.getByTestId("recovery-redeem-form__submit"));
}

beforeEach(() => {
  push.mockClear();
  login.mockReset();
  login.mockResolvedValue(SIGNED_IN_SESSION);
  toastSuccess.mockClear();
  repoRedeem.mockClear();
  outcome = { kind: RedeemRecoverySecretOutcomeKind.REDEEMED };
  online = true;
});

/**
 * The window between the server render and the hydration commit is the whole subject here. In
 * it React has not wired `onSubmit`, so a submit — a click, or Enter in the field — is a NATIVE
 * form submit: no `method` means GET, no `action` means the current URL, and the one registered
 * field is appended as a query parameter. The recovery secret would land in the address bar, the
 * history entry, the `Referer` of the next request and the container access log, which has no
 * TTL and no erasure path — the exact sink this whole flow exists to keep it out of, for a
 * credential that stays valid for ten years.
 */
describe("RecoveryRedeemForm — server render, before hydration", () => {
  const html = (): string => renderToString(<RecoveryRedeemForm />);

  it("ships the submit disabled, so no unhydrated submit can fire the native GET", () => {
    const markup = html();

    const button = markup.match(/<button[^>]*data-testid="recovery-redeem-form__submit"[^>]*>/);
    expect(button).not.toBeNull();
    // The attribute, not the substring: the button's Tailwind classes carry `disabled:opacity-50`,
    // so a `toContain("disabled")` here passes whether or not the control is actually disabled.
    expect(button?.[0]).toMatch(/\sdisabled=""/);
  });

  it("declares itself unhydrated, which is the flag the disabled state is derived from", () => {
    // Both halves are the assertion: the negative one alone is satisfied by DELETING the
    // attribute, so the pair pins that it is absent before hydration and present after.
    expect(html()).not.toContain('data-hydrated="true"');

    render(<RecoveryRedeemForm />);

    expect(screen.getByTestId("recovery-redeem-form")).toHaveAttribute("data-hydrated", "true");
  });

  it("never nominates a GET target of its own", () => {
    const form = html().match(/<form[^>]*data-testid="recovery-redeem-form"[^>]*>/);

    expect(form).not.toBeNull();
    expect(form?.[0]).not.toContain("action=");
    expect(form?.[0]).not.toContain('method="get"');
  });

  it("carries the secret as a named field — which is why the disabled submit matters", () => {
    // `name="secret"` is what a native GET would turn into `?secret=<selector>.<verifier>`.
    // Asserting it is present keeps the guard above honest: if the field ever stopped being
    // named, that test would go green for the wrong reason.
    expect(html()).toContain('name="secret"');
  });
});

describe("RecoveryRedeemForm — presenting the secret", () => {
  it("hands the typed secret to the port and signs in on a redeem", async () => {
    render(<RecoveryRedeemForm />);
    submit();

    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice"));
    expect(repoRedeem).toHaveBeenCalledWith(SECRET);
    expect(login).toHaveBeenCalledTimes(1);
    expect(toastSuccess).toHaveBeenCalledWith("Signed in");
  });

  it("never puts the secret anywhere but the field it was typed into", async () => {
    render(<RecoveryRedeemForm />);
    submit();

    await waitFor(() => expect(push).toHaveBeenCalled());
    // The destination is static; a secret riding a `?next=`-style parameter is the failure this
    // whole surface is built around.
    expect(push).toHaveBeenCalledWith("/backoffice");
    expect(window.location.search).toBe("");
  });

  /**
   * `ConnectivityButton` disables only the button, so Enter in the field reaches the form while
   * a submit is in flight. A second presentation spends another unit of the per-selector
   * redemption budget that is what makes guessing expensive, and the loser of the race taps its
   * invalid message over the winner's success.
   */
  it("swallows a second submit while one is in flight, so the secret is presented once", async () => {
    let settle: (result: RedeemRecoverySecretOutcome) => void = () => {};
    repoRedeem.mockImplementationOnce(
      () =>
        new Promise<RedeemRecoverySecretOutcome>((resolve) => {
          settle = resolve;
        }),
    );
    render(<RecoveryRedeemForm />);

    submit();
    await waitFor(() => expect(repoRedeem).toHaveBeenCalledTimes(1));

    fireEvent.submit(screen.getByTestId("recovery-redeem-form"));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(repoRedeem).toHaveBeenCalledTimes(1);

    settle({ kind: RedeemRecoverySecretOutcomeKind.REDEEMED });
    await waitFor(() => expect(push).toHaveBeenCalledWith("/backoffice"));
    expect(repoRedeem).toHaveBeenCalledTimes(1);
  });

  it("frees the latch once a refused attempt settles, so the typo is retryable", async () => {
    outcome = { kind: RedeemRecoverySecretOutcomeKind.INVALID_SECRET };
    render(<RecoveryRedeemForm />);

    submit("wrong-secret");
    expect(await screen.findByTestId("recovery-redeem-form__invalid")).toBeInTheDocument();

    submit();
    await waitFor(() => expect(repoRedeem).toHaveBeenCalledTimes(2));
  });
});

describe("RecoveryRedeemForm — refusals", () => {
  it("restates one opaque message on a refused secret and claims nothing", async () => {
    outcome = { kind: RedeemRecoverySecretOutcomeKind.INVALID_SECRET };
    render(<RecoveryRedeemForm />);
    submit();

    const refusal = await screen.findByTestId("recovery-redeem-form__invalid");
    // Malformed, unknown, expired, already spent and budget-exhausted are one message on
    // purpose: anything finer tells whoever is guessing which half of a secret was right.
    expect(refusal).toHaveTextContent(INVALID_SECRET_MESSAGE);
    expect(refusal).toHaveAttribute("role", "alert");
    expect(push).not.toHaveBeenCalled();
    expect(login).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
    // The form stays: a typo and a spent secret are indistinguishable here, and the typo is the
    // recoverable one.
    expect(screen.getByTestId("recovery-redeem-form")).toBeInTheDocument();
  });

  it.each([
    [RedeemRecoverySecretOutcomeKind.SUSPENDED, "access-wall--suspended"],
    [RedeemRecoverySecretOutcomeKind.DEACTIVATED, "access-wall--deactivated"],
  ])("replaces the form with the %s wall, which invites no retry", async (kind, wall) => {
    outcome = { kind } as RedeemRecoverySecretOutcome;
    render(<RecoveryRedeemForm />);
    submit();

    expect(await screen.findByTestId(wall)).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-redeem-form")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it("surfaces the neutral retryable error when the request throws, never blaming the secret", async () => {
    repoRedeem.mockRejectedValueOnce(new Error("network down"));
    render(<RecoveryRedeemForm />);
    submit();

    expect(await screen.findByTestId("recovery-redeem-form__error")).toBeInTheDocument();
    expect(screen.queryByTestId("recovery-redeem-form__invalid")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it("does not announce a sign-in the session probe could not confirm", async () => {
    // The secret is already spent by this point, so the one thing that must not happen is a
    // success the user believes: navigating would hand RequireAuth an unauthenticated provider
    // and bounce them back out with nothing left to present.
    login.mockResolvedValue(null);
    render(<RecoveryRedeemForm />);
    submit();

    expect(await screen.findByTestId("recovery-redeem-form__unconfirmed")).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it("tells the unconfirmed user not to re-present a secret that is already spent", async () => {
    // The redemption COMMITTED here — cookie set, row retired — so the neutral retryable error is
    // the wrong report: it sends the account's only credential holder back to a field that can
    // now answer nothing but the opaque refusal. It has to be distinguishable from the request
    // that never landed, which is the case above it.
    login.mockResolvedValue(null);
    render(<RecoveryRedeemForm />);
    submit();

    const notice = await screen.findByTestId("recovery-redeem-form__unconfirmed");

    expect(notice).toHaveTextContent("it only works once");
    expect(screen.queryByTestId("recovery-redeem-form__error")).not.toBeInTheDocument();
    expect(screen.queryByTestId("recovery-redeem-form__invalid")).not.toBeInTheDocument();
  });

  it("offers the way in the spent secret can no longer provide", async () => {
    // Without a route out of this state the user is stranded holding a valid session cookie one
    // navigation away from being inside — which is the whole defect, not the wording.
    login.mockResolvedValue(null);
    render(<RecoveryRedeemForm />);
    submit();

    fireEvent.click(await screen.findByTestId("recovery-redeem-form__continue"));

    expect(push).toHaveBeenCalledWith("/backoffice");
  });
});

describe("RecoveryRedeemForm — connectivity", () => {
  it("blocks the submit and says why while the browser reports no connection", () => {
    online = false;
    render(<RecoveryRedeemForm />);

    expect(screen.getByTestId("recovery-redeem-form__offline")).toBeInTheDocument();
    expect(screen.getByTestId("recovery-redeem-form__submit")).toBeDisabled();
  });

  it("keeps the typed secret across the offline band, so signal returning is enough", () => {
    const { rerender } = render(<RecoveryRedeemForm />);
    typeSecret();

    online = false;
    rerender(<RecoveryRedeemForm />);

    expect(screen.getByTestId("recovery-redeem-form__secret")).toHaveValue(SECRET);
  });
});

describe("The /recovery route", () => {
  it("names itself, so the navigation is announced", () => {
    expect(metadata.title).toBe("Use your recovery secret");
  });

  it("mounts the redeem form and nothing that reads the secret from the URL", () => {
    window.history.replaceState(null, "", `/recovery?secret=${SECRET}`);

    render(<RecoveryPage />);

    expect(screen.getByTestId("recovery-redeem-form")).toBeInTheDocument();
    // The field starts empty whatever the URL says: this page takes no parameters, and a secret
    // reaching it through one would already be in the history entry and the access log.
    expect(screen.getByTestId("recovery-redeem-form__secret")).toHaveValue("");

    window.history.replaceState(null, "", "/recovery");
  });
});
