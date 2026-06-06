import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import EditBankPage from "@/app/backoffice/banks/[id]/edit/page";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { ACME as BANK } from "./_fixtures";

/**
 * Editing a bank that a colleague deleted in parallel: the PUT answers 404
 * `bank-not-found`, the persistent surface offers "Refresh", and Refresh
 * re-runs the page load — which now 404s — landing on the not-found empty
 * state with focus on its "Back to banks" CTA (never <body>).
 */

const push = vi.hoisted(() => vi.fn());
const refresh = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", async () => ({
  ...(await import("./_mocks")).routerMock({ push, refresh }),
  useParams: () => ({ id: "11111111-1111-4111-8111-111111111111" }),
}));

const findRun = vi.hoisted(() => vi.fn());
const updateRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeFindBank: { run: findRun },
    BackOfficeUpdateBank: { run: updateRun },
  }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

const STALE_PROBLEM: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000404",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000404",
};

const SERVER_PROBLEM: ProblemDetails = {
  type: "unhandled-exception",
  title: "Something went wrong.",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000500",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000500",
};

describe("EditBankPage — stale bank recovery", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    findRun.mockResolvedValue(BANK);
  });

  it("PUT 404 → Refresh → not-found empty state with focus on the back-to-list CTA", async () => {
    updateRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    render(<EditBankPage />);
    await screen.findByTestId("bank-form");

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Renamed" } });
    fireEvent.submit(screen.getByTestId("bank-form"));

    // The persistent surface carries the verbatim 404 with a Refresh action.
    const surface = await screen.findByTestId("bank-form__mutation-error");
    expect(surface).toHaveTextContent("Bank not found");
    const refreshButton = screen.getByTestId("bank-form__mutation-error-refresh");

    // The re-load now finds the bank gone.
    findRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    fireEvent.click(refreshButton);

    const backToList = await screen.findByTestId("banks-edit__back-to-list");
    expect(screen.getByTestId("banks-edit__not-found")).toBeInTheDocument();
    // The form (and its surface) unmounted together with the not-found landing.
    expect(screen.queryByTestId("bank-form")).toBeNull();
    expect(screen.queryByTestId("bank-form__mutation-error")).toBeNull();
    await waitFor(() => expect(backToList).toHaveFocus());
  });

  it("Refresh landing on a load error focuses the page container, never <body>", async () => {
    updateRun.mockRejectedValue(new HttpError(STALE_PROBLEM));
    render(<EditBankPage />);
    await screen.findByTestId("bank-form");

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Renamed" } });
    fireEvent.submit(screen.getByTestId("bank-form"));
    const refreshButton = await screen.findByTestId("bank-form__mutation-error-refresh");

    // The re-load fails with a non-404: the error branch renders no CTA, so
    // the armed focus must fall back to the container.
    findRun.mockRejectedValue(new HttpError(SERVER_PROBLEM));
    fireEvent.click(refreshButton);

    await waitFor(() => expect(screen.getByTestId("banks-edit")).toHaveFocus());
    expect(screen.queryByTestId("bank-form")).toBeNull();
  });
});
