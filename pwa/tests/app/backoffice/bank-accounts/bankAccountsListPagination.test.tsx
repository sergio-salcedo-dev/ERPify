import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountsListPage from "@/app/backoffice/bank-accounts/page";
import { ACME_CHECKING, BETA_RESERVE, resourcePage } from "./_fixtures";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const search = vi.hoisted(() => vi.fn());
const follow = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeBankAccountCrudRepository: { search },
    BackOfficeBankAccountResourceNavigator: { follow },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock(),
);

describe("BankAccountsListPage — cursor pagination", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("follows the server-issued next link verbatim when Next is clicked", async () => {
    const nextLink = "/api/v1/backoffice/bank-accounts?after=abc&limit=25";
    search.mockResolvedValueOnce(
      resourcePage([ACME_CHECKING], { hasNext: true, links: { next: nextLink, prev: null } }),
    );
    follow.mockResolvedValueOnce(
      resourcePage([BETA_RESERVE], { hasPrev: true, links: { next: null, prev: "/api/…" } }),
    );

    render(<BankAccountsListPage />);
    await screen.findByTestId(`bank-accounts-list__row-${ACME_CHECKING.id}`);

    fireEvent.click(screen.getByRole("button", { name: "Next page" }));

    await waitFor(() => {
      expect(follow).toHaveBeenCalledWith(nextLink);
    });
    await screen.findByTestId(`bank-accounts-list__row-${BETA_RESERVE.id}`);
  });
});
