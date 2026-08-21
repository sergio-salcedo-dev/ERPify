import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { BankAccountForm } from "@/app/backoffice/banks/[id]/accounts/_components/BankAccountForm";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";

const BANK_ID = "11111111-1111-7111-8111-111111111111";
const VALID_IBAN = "ES9121000418450200051332";

const push = vi.hoisted(() => vi.fn());
const refresh = vi.hoisted(() => vi.fn());
const createRun = vi.hoisted(() => vi.fn());
const updateRun = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh, back: vi.fn() }),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeCreateBankAccount") return { run: createRun };
      if (token === "BackOfficeUpdateBankAccount") return { run: updateRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
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

const INITIAL = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: VALID_IBAN,
  bic: null as string | null,
  alias: null as string | null,
  currency: "EUR" as const,
  status: "ACTIVE" as const,
};

function problem(overrides: Partial<ProblemDetails> = {}): ProblemDetails {
  return {
    type: "unhandled-exception",
    title: "Something went wrong.",
    status: 500,
    detail: "The account could not be saved.",
    instance: "01H-instance",
    "correlation-id": "01H-correlation",
    ...overrides,
  };
}

function fillValid(): void {
  fireEvent.change(screen.getByTestId("bank-account-form__holder-name"), {
    target: { value: "Acme Corp" },
  });
  fireEvent.change(screen.getByTestId("bank-account-form__iban"), {
    target: { value: VALID_IBAN },
  });
}

function submit(): void {
  fireEvent.submit(screen.getByTestId("bank-account-form"));
}

describe("BankAccountForm — persistent mutation-error surface", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows MutationError on a 500 and takes focus", async () => {
    createRun.mockRejectedValue(new HttpError(problem()));
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();

    const surface = await screen.findByTestId("bank-account-form__mutation-error");
    expect(within(surface).getByText("Something went wrong.")).toBeInTheDocument();
    expect(within(surface).getByText("The account could not be saved.")).toBeInTheDocument();
    await waitFor(() => expect(surface).toHaveFocus());
  });

  it("shows MutationError on a network-style problem (status 0)", async () => {
    createRun.mockRejectedValue(
      new HttpError(problem({ status: 0, title: "Network request failed." })),
    );
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();

    const surface = await screen.findByTestId("bank-account-form__mutation-error");
    expect(within(surface).getByText("Network request failed.")).toBeInTheDocument();
  });

  it("maps a 422 unique-IBAN violation onto the iban field, not the whole surface", async () => {
    createRun.mockRejectedValue(
      new HttpError(
        problem({
          status: 422,
          type: "validation-failed",
          violations: [{ field: "iban", message: "This IBAN is already registered." }],
        }),
      ),
    );
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();

    expect(await screen.findByText("This IBAN is already registered.")).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.queryByTestId("bank-account-form__mutation-error")).not.toBeInTheDocument(),
    );
  });

  it("shows both a field error and the surface when 422 violations are partially mapped", async () => {
    createRun.mockRejectedValue(
      new HttpError(
        problem({
          status: 422,
          type: "validation-failed",
          title: "Validation failed.",
          violations: [
            { field: "bic", message: "The BIC does not match the IBAN country." },
            { field: "unknown", message: "Unmapped error." },
          ],
        }),
      ),
    );
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();

    expect(await screen.findByText("The BIC does not match the IBAN country.")).toBeInTheDocument();
    const surface = await screen.findByTestId("bank-account-form__mutation-error");
    expect(within(surface).getByText("Validation failed.")).toBeInTheDocument();
  });

  it("navigates and toasts on success, clearing any prior surface", async () => {
    createRun.mockRejectedValueOnce(new HttpError(problem()));
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();
    await screen.findByTestId("bank-account-form__mutation-error");

    createRun.mockResolvedValueOnce(undefined);
    submit();

    await waitFor(() => expect(push).toHaveBeenCalledWith(`/backoffice/banks/${BANK_ID}/accounts`));
    expect(screen.queryByTestId("bank-account-form__mutation-error")).not.toBeInTheDocument();
  });

  it("never renders a recovery action in create mode, even on a not-found type", async () => {
    createRun.mockRejectedValue(
      new HttpError(problem({ status: 404, type: "bank-account-not-found", title: "Not found." })),
    );
    render(<BankAccountForm mode={PersistenceAction.CREATING} bankId={BANK_ID} />);

    fillValid();
    submit();

    await screen.findByTestId("bank-account-form__mutation-error");
    expect(
      screen.queryByTestId("bank-account-form__mutation-error-refresh"),
    ).not.toBeInTheDocument();
  });

  it("renders Refresh for a stale 404 in edit mode and invokes onStaleAccount", async () => {
    const onStaleAccount = vi.fn();
    updateRun.mockRejectedValue(
      new HttpError(
        problem({ status: 404, type: "bank-account-not-found", title: "Account not found." }),
      ),
    );
    render(
      <BankAccountForm
        mode={PersistenceAction.UPDATING}
        bankId={BANK_ID}
        initial={INITIAL}
        onStaleAccount={onStaleAccount}
      />,
    );

    fireEvent.change(screen.getByTestId("bank-account-form__holder-name"), {
      target: { value: "Renamed Holder" },
    });
    submit();

    const refreshButton = await screen.findByTestId("bank-account-form__mutation-error-refresh");
    fireEvent.click(refreshButton);
    expect(onStaleAccount).toHaveBeenCalledTimes(1);
  });

  it("sends a canonicalised IBAN on a successful edit and never a status (that is its own control)", async () => {
    updateRun.mockResolvedValueOnce(undefined);
    render(
      <BankAccountForm mode={PersistenceAction.UPDATING} bankId={BANK_ID} initial={INITIAL} />,
    );

    expect(screen.queryByTestId("bank-account-form__status")).toBeNull();

    fireEvent.change(screen.getByTestId("bank-account-form__iban"), {
      target: { value: "es91 2100 0418 4502 0005 1332" },
    });
    submit();

    await waitFor(() => expect(updateRun).toHaveBeenCalled());
    expect(updateRun).toHaveBeenCalledWith(
      INITIAL.id,
      expect.objectContaining({ iban: VALID_IBAN }),
    );
    expect(updateRun.mock.calls[0][1]).not.toHaveProperty("status");
  });

  it("sends a canonicalised BIC and a filled alias, and collapses either back to null when cleared", async () => {
    updateRun.mockResolvedValue(undefined);
    render(
      <BankAccountForm mode={PersistenceAction.UPDATING} bankId={BANK_ID} initial={INITIAL} />,
    );

    fireEvent.change(screen.getByTestId("bank-account-form__bic"), {
      target: { value: "caix esbb xxx" },
    });
    fireEvent.change(screen.getByTestId("bank-account-form__alias"), {
      target: { value: "Payroll" },
    });
    submit();

    await waitFor(() => expect(updateRun).toHaveBeenCalled());
    expect(updateRun).toHaveBeenCalledWith(
      INITIAL.id,
      expect.objectContaining({ bic: "CAIXESBBXXX", alias: "Payroll" }),
    );

    // Cleared back to empty: the optional collapses to null rather than "".
    fireEvent.change(screen.getByTestId("bank-account-form__bic"), { target: { value: "" } });
    fireEvent.change(screen.getByTestId("bank-account-form__alias"), { target: { value: "" } });
    submit();

    await waitFor(() => expect(updateRun).toHaveBeenCalledTimes(2));
    expect(updateRun).toHaveBeenLastCalledWith(
      INITIAL.id,
      expect.objectContaining({ bic: null, alias: null }),
    );
  });
});
