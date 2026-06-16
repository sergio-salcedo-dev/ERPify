import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountsPage from "@/app/backoffice/banks/[id]/accounts/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type { BankAccountSearchPage } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";

const VALID_ID = "11111111-1111-7111-8111-111111111111";

const idHolder = vi.hoisted(() => ({ id: "11111111-1111-7111-8111-111111111111" }));
const searchRun = vi.hoisted(() => vi.fn());
const followRun = vi.hoisted(() => vi.fn());

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: idHolder.id }),
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBankAccounts") return { run: searchRun };
      if (token === "BackOfficeBankAccountSearchNavigator") return { follow: followRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

const ACCOUNT = BankAccount.fromPrimitives({
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
});

function page(
  accounts: BankAccount[],
  overrides: Partial<BankAccountSearchPage> = {},
): BankAccountSearchPage {
  return {
    accounts,
    hasNext: false,
    hasPrev: false,
    count: null,
    links: { next: null, prev: null },
    ...overrides,
  };
}

describe("BankAccountsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    idHolder.id = VALID_ID;
  });

  it("loads and renders the accounts table on a cold load", async () => {
    searchRun.mockResolvedValueOnce(page([ACCOUNT]));
    render(<BankAccountsPage />);

    await waitFor(() => {
      expect(screen.getByTestId(`bank-accounts-table__row-${ACCOUNT.id}`)).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledWith(VALID_ID, { filters: [], sort: null, limit: 25 });
    // IBAN is masked by default.
    expect(screen.getByText("ES•• ···· 1332")).toBeInTheDocument();
    expect(screen.queryByText(ACCOUNT.iban)).toBeNull();
  });

  it("shows the empty state when the bank has no accounts", async () => {
    searchRun.mockResolvedValueOnce(page([]));
    render(<BankAccountsPage />);

    await waitFor(() => {
      expect(screen.getByText("This bank has no associated accounts.")).toBeInTheDocument();
    });
  });

  it("renders the error boundary with a correlation id and retries", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(page([ACCOUNT]));
    render(<BankAccountsPage />);

    const retry = await screen.findByTestId("bank-accounts__retry");
    expect(screen.getByTestId("correlation-id-display")).toBeInTheDocument();

    fireEvent.click(retry);
    await waitFor(() => {
      expect(screen.getByTestId(`bank-accounts-table__row-${ACCOUNT.id}`)).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
  });

  it("guards a malformed bank id without hitting the network", async () => {
    idHolder.id = "not-a-uuid";
    render(<BankAccountsPage />);

    await waitFor(() => {
      expect(screen.getByTestId("bank-accounts__retry")).toBeInTheDocument();
    });
    expect(searchRun).not.toHaveBeenCalled();
  });

  it("follows the server-issued next link verbatim when paging", async () => {
    const next = "/api/v1/backoffice/banks/" + VALID_ID + "/accounts?limit=25&after=opaque";
    searchRun.mockResolvedValueOnce(
      page([ACCOUNT], { hasNext: true, links: { next, prev: null } }),
    );
    followRun.mockResolvedValueOnce(page([ACCOUNT]));

    render(<BankAccountsPage />);
    const nextBtn = await screen.findByTestId("bank-accounts-pagination__next");
    fireEvent.click(nextBtn);

    await waitFor(() => expect(followRun).toHaveBeenCalledWith(next));
  });
});
