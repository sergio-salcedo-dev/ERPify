import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { BankForm } from "@/app/backoffice/banks/_components/BankForm";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { PersistenceAction } from "@/context/shared/domain/types/status";
import { ACME } from "./_fixtures";

// Same bank as ACME but freshly persisted, so updatedAt collapses onto createdAt.
const UPDATED = Bank.fromPrimitives({ ...ACME, updatedAt: ACME.createdAt });

const INITIAL = { id: UPDATED.id, name: "Acme Savings", shortName: "ACME" };

const mocks = await vi.hoisted(async () => (await import("./_mocks")).bankFormMocks());
vi.mock("next/navigation", () => mocks.navigation);
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => mocks.container);
vi.mock("@/context/shared/notification/infrastructure/Toast", () => mocks.toast);

const { push, createRun, updateRun } = mocks;

function problem(overrides: Partial<ProblemDetails> = {}): ProblemDetails {
  return {
    type: "unhandled-exception",
    title: "Something went wrong.",
    status: 500,
    detail: "The bank could not be saved.",
    instance: "01H-instance",
    "correlation-id": "01H-correlation",
    ...overrides,
  };
}

function fillValidValues(): void {
  fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Acme Savings" } });
  fireEvent.change(screen.getByTestId("bank-form__short-name"), { target: { value: "ACME" } });
}

function submit(): void {
  fireEvent.submit(screen.getByTestId("bank-form"));
}

describe("BankForm — persistent mutation-error surface", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("unmapped failures show the surface, focused on mount", () => {
    it("shows MutationError with the verbatim problem on a 500 and takes focus", async () => {
      createRun.mockRejectedValue(new HttpError(problem()));
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();

      const surface = await screen.findByTestId("bank-form__mutation-error");
      expect(within(surface).getByText("Something went wrong.")).toBeInTheDocument();
      expect(within(surface).getByText("The bank could not be saved.")).toBeInTheDocument();
      // A single live announcement — the ProblemDisplay's own role="alert".
      expect(within(surface).getAllByRole("alert")).toHaveLength(1);
      // Focus contract: the wrapper (tabIndex={-1}) is focused, not <body>.
      await waitFor(() => expect(surface).toHaveFocus());
    });

    it("shows MutationError on a network-style problem (status 0)", async () => {
      createRun.mockRejectedValue(
        new HttpError(problem({ status: 0, title: "Network request failed." })),
      );
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();

      const surface = await screen.findByTestId("bank-form__mutation-error");
      expect(within(surface).getByText("Network request failed.")).toBeInTheDocument();
    });

    it("shows MutationError on a 422 with no violations", async () => {
      createRun.mockRejectedValue(
        new HttpError(problem({ status: 422, type: "validation-failed", title: "Unprocessable." })),
      );
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();

      expect(await screen.findByTestId("bank-form__mutation-error")).toBeInTheDocument();
    });
  });

  it("dismisses the surface on × without altering the form", async () => {
    createRun.mockRejectedValue(new HttpError(problem()));
    render(<BankForm mode={PersistenceAction.CREATING} />);

    fillValidValues();
    submit();

    const surface = await screen.findByTestId("bank-form__mutation-error");
    fireEvent.click(within(surface).getByTestId("bank-form__mutation-error__dismiss"));

    await waitFor(() =>
      expect(screen.queryByTestId("bank-form__mutation-error")).not.toBeInTheDocument(),
    );
    // The form keeps the typed values.
    expect(screen.getByTestId("bank-form__name")).toHaveValue("Acme Savings");
    expect(screen.getByTestId("bank-form__short-name")).toHaveValue("ACME");
  });

  describe("retry lifecycle", () => {
    it("keeps the previous error during the retry flight, then replaces it on a new failure", async () => {
      createRun.mockRejectedValueOnce(
        new HttpError(problem({ "correlation-id": "first", detail: "First failure." })),
      );
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();
      expect(await screen.findByText("First failure.")).toBeInTheDocument();

      let rejectSecond: (reason: unknown) => void = () => {};
      createRun.mockReturnValueOnce(
        new Promise((_resolve, reject) => {
          rejectSecond = reject;
        }),
      );
      submit();

      // In flight: the first error is still visible.
      expect(screen.getByText("First failure.")).toBeInTheDocument();

      rejectSecond(
        new HttpError(problem({ "correlation-id": "second", detail: "Second failure." })),
      );
      expect(await screen.findByText("Second failure.")).toBeInTheDocument();
      expect(screen.queryByText("First failure.")).not.toBeInTheDocument();
    });

    it("navigates and toasts on a successful retry after a previous failure", async () => {
      createRun.mockRejectedValueOnce(new HttpError(problem()));
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();
      await screen.findByTestId("bank-form__mutation-error");

      createRun.mockResolvedValueOnce(UPDATED);
      submit();

      await waitFor(() => expect(push).toHaveBeenCalled());
      // The success cleared the surface itself — navigation is mocked, so the
      // form never unmounted and a leftover surface would still be visible.
      expect(screen.queryByTestId("bank-form__mutation-error")).not.toBeInTheDocument();
    });
  });

  describe("400 violations vs the surface", () => {
    it("clears a previous surface when every violation maps to a field", async () => {
      createRun.mockRejectedValueOnce(new HttpError(problem()));
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();
      await screen.findByTestId("bank-form__mutation-error");

      createRun.mockRejectedValueOnce(
        new HttpError(
          problem({
            status: 400,
            type: "validation-failed",
            violations: [{ field: "name", message: "The name field is required." }],
          }),
        ),
      );
      submit();

      expect(await screen.findByText("The name field is required.")).toBeInTheDocument();
      await waitFor(() =>
        expect(screen.queryByTestId("bank-form__mutation-error")).not.toBeInTheDocument(),
      );
    });

    it("shows both field errors and the surface when violations are partially mapped", async () => {
      createRun.mockRejectedValue(
        new HttpError(
          problem({
            status: 400,
            type: "validation-failed",
            title: "Validation failed.",
            violations: [
              { field: "name", message: "The name field is required." },
              { field: "iban", message: "Unknown field error." },
            ],
          }),
        ),
      );
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();

      expect(await screen.findByText("The name field is required.")).toBeInTheDocument();
      const surface = await screen.findByTestId("bank-form__mutation-error");
      expect(within(surface).getByText("Validation failed.")).toBeInTheDocument();
    });
  });

  describe("typed recovery action", () => {
    it("renders Refresh for a stale 404 in edit mode and invokes onStaleBank", async () => {
      const onStaleBank = vi.fn();
      updateRun.mockRejectedValue(
        new HttpError(problem({ status: 404, type: "bank-not-found", title: "Bank not found." })),
      );
      render(
        <BankForm mode={PersistenceAction.UPDATING} initial={INITIAL} onStaleBank={onStaleBank} />,
      );

      fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Renamed" } });
      submit();

      const refreshButton = await screen.findByTestId("bank-form__mutation-error-refresh");
      fireEvent.click(refreshButton);
      expect(onStaleBank).toHaveBeenCalledTimes(1);
    });

    it("never renders a recovery action in create mode, even on a bank-not-found type", async () => {
      createRun.mockRejectedValue(
        new HttpError(problem({ status: 404, type: "bank-not-found", title: "Bank not found." })),
      );
      render(<BankForm mode={PersistenceAction.CREATING} />);

      fillValidValues();
      submit();

      await screen.findByTestId("bank-form__mutation-error");
      expect(screen.queryByTestId("bank-form__mutation-error-refresh")).not.toBeInTheDocument();
    });

    it("renders no recovery action in edit mode for a non-404 type", async () => {
      updateRun.mockRejectedValue(new HttpError(problem()));
      render(
        <BankForm mode={PersistenceAction.UPDATING} initial={INITIAL} onStaleBank={vi.fn()} />,
      );

      fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Renamed" } });
      submit();

      await screen.findByTestId("bank-form__mutation-error");
      expect(screen.queryByTestId("bank-form__mutation-error-refresh")).not.toBeInTheDocument();
    });
  });
});
