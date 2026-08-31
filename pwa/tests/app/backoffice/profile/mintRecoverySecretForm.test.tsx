import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const mint = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: () => ({
      read: vi.fn(),
      mint: (...args: unknown[]) => mint(...args),
      revoke: vi.fn(),
    }),
  },
}));

import { MintRecoverySecretForm } from "@/app/backoffice/profile/_components/MintRecoverySecretForm";

const PASSWORD = "correct-horse-battery";

const onMinted = vi.fn();
const onProblem = vi.fn();

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

function renderForm() {
  return render(<MintRecoverySecretForm onMinted={onMinted} onProblem={onProblem} />);
}

function typePassword(value: string): void {
  fireEvent.change(screen.getByTestId("mint-recovery-secret__current-password"), {
    target: { value },
  });
}

beforeEach(() => {
  mint.mockReset();
  onMinted.mockReset();
  onProblem.mockReset();
  mint.mockResolvedValue({
    secret: "sel.ver",
    mintedAt: "2026-08-01T09:00:00.000Z",
    expiresAt: "2036-08-01T09:00:00.000Z",
  });
});

/**
 * This form's field is named, so a submit React has not wired yet is a native GET carrying
 * `?currentPassword=<plaintext>` into the URL, the history entry, the `Referer` of the next
 * request and the container access log — a sink with no TTL and no erasure owner. The guard
 * that stops it is one expression on one line, which is why it is asserted from both sides here
 * rather than left to review.
 */
describe("MintRecoverySecretForm — server render, before hydration", () => {
  const html = (): string =>
    renderToString(<MintRecoverySecretForm onMinted={onMinted} onProblem={onProblem} />);

  it("ships the submit disabled, so no unhydrated submit can present the password", () => {
    const button = html().match(/<button[^>]*data-testid="mint-recovery-secret__submit"[^>]*>/);

    expect(button).not.toBeNull();
    // The attribute, not the substring: the button's Tailwind classes carry `disabled:opacity-50`,
    // so a `toContain("disabled")` here passes whether or not the control is actually disabled.
    expect(button?.[0]).toMatch(/\sdisabled=""/);
  });

  it("declares itself unhydrated, which is the flag the disabled state is derived from", () => {
    // Both halves are the assertion: the negative one alone is satisfied by DELETING the
    // attribute, so the pair pins that it is absent before hydration and present after.
    expect(html()).not.toContain('data-hydrated="true"');

    renderForm();

    expect(screen.getByTestId("mint-recovery-secret")).toHaveAttribute("data-hydrated", "true");
  });

  it("never nominates a GET target of its own", () => {
    const form = html().match(/<form[^>]*data-testid="mint-recovery-secret"[^>]*>/);

    expect(form).not.toBeNull();
    // No `action` and no `method`: a native submit would resolve against the current URL, which
    // is what puts the field's value in the address bar rather than in a request body.
    expect(form?.[0]).not.toMatch(/\saction=/);
    expect(form?.[0]).not.toMatch(/\smethod=/);
  });
});

describe("MintRecoverySecretForm — one presentation per intent", () => {
  /**
   * `disabled` lands on the next React commit, so holding Enter in the field presents the same
   * password twice inside that window. Each attempt spends a unit of the shared per-identity
   * credential-proof bucket, which is the only ceiling on guessing this password from a live
   * session — and one that never feeds the persisted lockout.
   */
  it("reaches the port once however fast the form is submitted twice", async () => {
    let settle: () => void = () => {};
    mint.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          settle = () => resolve({ secret: "sel.ver", mintedAt: "x", expiresAt: "y" });
        }),
    );

    renderForm();
    typePassword(PASSWORD);

    const form = screen.getByTestId("mint-recovery-secret");
    fireEvent.submit(form);
    fireEvent.submit(form);

    await waitFor(() => expect(mint).toHaveBeenCalledTimes(1));
    settle();
    await waitFor(() => expect(onMinted).toHaveBeenCalledTimes(1));
    expect(mint).toHaveBeenCalledTimes(1);
  });
});

describe("MintRecoverySecretForm — where each refusal lands", () => {
  it("hangs a rejected password on the field the user typed it in, not on the banner", async () => {
    mint.mockRejectedValue(
      new HttpError(
        problem(
          HttpStatus.FORBIDDEN,
          "invalid-current-password",
          "The current password is not correct.",
        ),
      ),
    );

    renderForm();
    typePassword("wrong-password");
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    expect(await screen.findByText("The current password is not correct.")).toBeInTheDocument();
    // The banner is the owner's surface for everything this form cannot explain in place; a
    // wrong password is not one of those, and routing it there loses the field association.
    expect(onProblem).not.toHaveBeenCalled();
    expect(onMinted).not.toHaveBeenCalled();
  });

  it("sends every other refusal up to the owner's banner", async () => {
    const conflict = problem(
      HttpStatus.CONFLICT,
      "recovery-secret-already-exists",
      "This account already holds a recovery secret.",
    );
    mint.mockRejectedValue(new HttpError(conflict));

    renderForm();
    typePassword(PASSWORD);
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    await waitFor(() => expect(onProblem).toHaveBeenCalledWith(conflict));
    expect(onMinted).not.toHaveBeenCalled();
  });

  it("releases the latch after a refusal, so a corrected password can be presented", async () => {
    mint.mockRejectedValueOnce(
      new HttpError(
        problem(
          HttpStatus.FORBIDDEN,
          "invalid-current-password",
          "The current password is not correct.",
        ),
      ),
    );

    renderForm();
    typePassword("wrong-password");
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));
    await screen.findByText("The current password is not correct.");

    typePassword(PASSWORD);
    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    await waitFor(() => expect(onMinted).toHaveBeenCalledTimes(1));
    expect(mint).toHaveBeenCalledTimes(2);
  });

  it("refuses to reach the port with no password at all", async () => {
    renderForm();

    fireEvent.submit(screen.getByTestId("mint-recovery-secret"));

    await waitFor(() => expect(screen.getByTestId("mint-recovery-secret")).toBeInTheDocument());
    expect(mint).not.toHaveBeenCalled();
  });
});
