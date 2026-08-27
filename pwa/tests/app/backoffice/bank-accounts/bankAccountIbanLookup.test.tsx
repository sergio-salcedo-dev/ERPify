import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankAccountIbanLookup } from "@/app/backoffice/bank-accounts/_components/BankAccountIbanLookup";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const push = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock({ push }));

const run = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeLookupBankAccountByIban: { run } }),
);

const ACCOUNT_ID = "33333333-3333-7000-8000-000000000001";
const ROW = {
  id: ACCOUNT_ID,
  bankId: "11111111-1111-7000-8000-000000000001",
  bankName: "JPMorgan Chase",
  bankShortName: "JPM",
  holderName: "Globex Corporation",
  iban: "DE89370400440532013000",
  bic: "DEUTDEFFXXX",
  alias: "Globex Treasury",
  currency: "EUR",
  status: "INACTIVE",
};

function problemOf(status: number, type: string, title: string): ProblemDetails {
  return {
    type,
    title,
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  };
}

function fillAndSubmit(iban: string): void {
  fireEvent.change(screen.getByTestId("bank-accounts-iban-lookup__input"), {
    target: { value: iban },
  });
  fireEvent.submit(screen.getByTestId("bank-accounts-iban-lookup"));
}

describe("BankAccountIbanLookup", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("navigates to the account's detail page on a match, canonicalizing the typed value", async () => {
    run.mockResolvedValue(ROW);
    render(<BankAccountIbanLookup />);

    fillAndSubmit("de89 3704 0044 0532 0130 00");

    await waitFor(() => {
      expect(run).toHaveBeenCalledWith("DE89370400440532013000");
    });
    await waitFor(() => {
      expect(push).toHaveBeenCalledWith(`/backoffice/bank-accounts/${ACCOUNT_ID}`);
    });
  });

  it("shows a lightweight inline result on a 404 — never the mutation-error surface", async () => {
    run.mockRejectedValue(
      new HttpError(problemOf(HttpStatus.NOT_FOUND, "bank-account-not-found", "Not found.")),
    );
    render(<BankAccountIbanLookup />);

    fillAndSubmit("ES9121000418450200051332");

    expect(await screen.findByTestId("bank-accounts-iban-lookup__not-found")).toHaveTextContent(
      "No account found for that IBAN.",
    );
    expect(screen.queryByTestId("bank-accounts-iban-lookup__error")).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it("shows the mutation-error surface on a genuine failure (non-404)", async () => {
    run.mockRejectedValue(new HttpError(problemOf(500, "about:blank", "Internal Server Error")));
    render(<BankAccountIbanLookup />);

    fillAndSubmit("ES9121000418450200051332");

    expect(await screen.findByTestId("bank-accounts-iban-lookup__error")).toBeInTheDocument();
    expect(screen.queryByTestId("bank-accounts-iban-lookup__not-found")).not.toBeInTheDocument();
  });

  it("applies only the latest request's outcome when a stale response resolves after a newer one", async () => {
    const SECOND_ROW = { ...ROW, id: "44444444-4444-7000-8000-000000000002" };
    let resolveFirst: (row: typeof ROW) => void = () => {};
    let resolveSecond: (row: typeof ROW) => void = () => {};
    run
      .mockImplementationOnce(() => new Promise((resolve) => (resolveFirst = resolve)))
      .mockImplementationOnce(() => new Promise((resolve) => (resolveSecond = resolve)));

    render(<BankAccountIbanLookup />);
    const form = screen.getByTestId("bank-accounts-iban-lookup");
    fireEvent.change(screen.getByTestId("bank-accounts-iban-lookup__input"), {
      target: { value: ROW.iban },
    });
    // Two submits before either resolves — the second (latest) request must win even if the
    // FIRST (stale) one resolves last, which is exactly what the request-id guard prevents.
    fireEvent.submit(form);
    fireEvent.submit(form);
    await waitFor(() => {
      expect(run).toHaveBeenCalledTimes(2);
    });

    resolveSecond(SECOND_ROW);
    await waitFor(() => {
      expect(push).toHaveBeenCalledWith(`/backoffice/bank-accounts/${SECOND_ROW.id}`);
    });
    push.mockClear();

    await act(async () => {
      resolveFirst(ROW);
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(push).not.toHaveBeenCalled();
  });

  it("rejects a malformed IBAN client-side without calling the use case", async () => {
    render(<BankAccountIbanLookup />);

    fillAndSubmit("not-an-iban");

    expect(await screen.findByText("Please enter a valid IBAN.")).toBeInTheDocument();
    expect(run).not.toHaveBeenCalled();
  });
});
