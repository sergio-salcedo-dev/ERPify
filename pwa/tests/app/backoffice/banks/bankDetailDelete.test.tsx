import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { ACME as BANK } from "./_fixtures";

/**
 * deleting a  bank from its detail page must redirect cleanly to the list and surface a
 * success toast, without flashing the "Bank not found" empty state while the
 * navigation settles.
 */

const push = vi.hoisted(() => vi.fn());
const refresh = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () => ({
  ...(await import("./_mocks")).routerMock({ push, refresh }),
  useParams: () => ({ id: "11111111-1111-4111-8111-111111111111" }),
}));

const findRun = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeFindBank: { run: findRun },
    BackOfficeDeleteBank: { run: deleteRun },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

describe("BankDetailPage — delete UX", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    findRun.mockResolvedValue(BANK);
    deleteRun.mockResolvedValue(undefined);
  });

  it("shows a success toast and redirects to the list after a successful delete", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__name")).toHaveTextContent("Acme Savings");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await waitFor(() => {
      expect(toastNotifier.success).toHaveBeenCalledWith("Bank deleted", {
        description: "Acme Savings",
      });
    });
    expect(push).toHaveBeenCalledWith("/backoffice/banks");
  });

  it("redirects cleanly without re-triggering the detail fetch and never flashes 'Bank not found'", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__name")).toHaveTextContent("Acme Savings");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await waitFor(() => {
      expect(push).toHaveBeenCalledWith("/backoffice/banks");
    });
    // A clean redirect must not call router.refresh(): that is what re-ran the
    // FindBank fetch for the deleted id and flashed the not-found state.
    expect(refresh).not.toHaveBeenCalled();
    expect(screen.queryByTestId("banks-detail__not-found")).toBeNull();
  });
});

const IN_USE_PROBLEM: ProblemDetails = {
  type: "bank-in-use",
  title: "Bank cannot be deleted: 3 associated bank accounts",
  status: 409,
  detail: "Remove or reassign the associated bank accounts first.",
  instance: "01926e7e-7b8a-7c4e-9f31-000000000409",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000409",
  bankId: BANK.id,
  accountCount: 3,
};

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

describe("BankDetailPage — failed delete lands in the persistent error surface", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    findRun.mockResolvedValue(BANK);
  });

  it("409 bank-in-use: the dialog closes, the error persists under the header with a View accounts recovery", async () => {
    deleteRun.mockRejectedValue(new HttpError(IN_USE_PROBLEM));
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__name");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    const surface = await screen.findByTestId("banks-detail__delete-error");
    expect(screen.queryByTestId("banks-detail__delete-dialog")).toBeNull();
    expect(surface).toHaveTextContent(IN_USE_PROBLEM.title);
    // A raced 409 recovers by routing to the accounts surface, not a refresh.
    expect(screen.queryByTestId("banks-detail__delete-error-refresh")).toBeNull();
    expect(screen.getByTestId("banks-detail__delete-error-view-accounts")).toHaveAttribute(
      "href",
      `/backoffice/banks/${BANK.id}/accounts`,
    );
    expect(surface).toHaveFocus();
    // The detail stays put: no redirect, no success toast.
    expect(push).not.toHaveBeenCalled();
    expect(toastNotifier.success).not.toHaveBeenCalled();
    expect(toastNotifier.error).toHaveBeenCalledWith("Couldn't delete bank — see error details");
  });

  it("404 stale: Refresh re-fetches and lands on the not-found empty state", async () => {
    deleteRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__name");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await screen.findByTestId("banks-detail__delete-error");
    findRun.mockRejectedValue(new HttpError(STALE_PROBLEM));

    fireEvent.click(screen.getByTestId("banks-detail__delete-error-refresh"));

    expect(await screen.findByTestId("banks-detail__not-found")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-detail__delete-error")).toBeNull();
    expect(screen.getByTestId("banks-detail__back-to-list")).toBeInTheDocument();
  });
});
