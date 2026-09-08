import { describe, expect, it, vi, beforeEach } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditInvestigationScreen } from "@/app/backoffice/audit/_components/AuditInvestigationScreen";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";
import type { AuditTimelineState } from "@/context/backoffice/audit/application/useAuditTimeline";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";

const replaceMock = vi.fn();
let searchParamsStr = "";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: replaceMock }),
  usePathname: () => "/backoffice/audit",
  useSearchParams: () => new URLSearchParams(searchParamsStr),
}));

let timelineState: AuditTimelineState;
vi.mock("@/context/backoffice/audit/application/useAuditTimeline", () => ({
  useAuditTimeline: () => timelineState,
}));

const ENTRY: AuditEntry = {
  id: "019f0691-2b5b-731e-9509-3470576159d6",
  occurredOn: "2026-06-12T12:00:00.000000+00:00",
  level: "security",
  action: "ACCESS_DENIED",
  actorType: "api_key",
  actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
  correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
  resourceType: "BankAccount",
  resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
  actorErased: false,
  resourceErased: false,
};

function stateWith(overrides: Partial<AuditTimelineState>): AuditTimelineState {
  return {
    state: ViewStatus.READY,
    entries: [ENTRY],
    pagination: null,
    paginationActions: { hasPrev: false, hasNext: false, goPrev: vi.fn(), goNext: vi.fn() },
    problem: null,
    reload: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  replaceMock.mockReset();
  searchParamsStr = "";
});

describe("AuditInvestigationScreen", () => {
  it("renders the screen header and the timeline when data is ready", () => {
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    expect(screen.getByRole("heading", { name: "Audit" })).toBeInTheDocument();
    expect(screen.getByTestId("audit-timeline")).toBeInTheDocument();
    expect(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`)).toBeInTheDocument();
  });

  it("deep-links a row open via ?entry by writing the URL param", () => {
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    fireEvent.click(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`));
    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock.mock.calls[0][0]).toContain(`entry=${ENTRY.id}`);
  });

  it("opens the drawer for the row named by ?entry", () => {
    searchParamsStr = `entry=${ENTRY.id}`;
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);
    expect(screen.getByTestId("audit-entry-drawer")).toBeInTheDocument();
    expect(screen.getAllByText("Access denied").length).toBeGreaterThan(0);
  });

  it("shows the filtered-to-zero empty state when a ready result is empty", () => {
    searchParamsStr = "level=security";
    timelineState = stateWith({ entries: [] });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("No results")).toBeInTheDocument();
  });

  it("shows the first-run empty state when the log is genuinely empty", () => {
    timelineState = stateWith({ state: ViewStatus.EMPTY, entries: [] });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("No activity recorded")).toBeInTheDocument();
  });

  it("renders the timeline for a bookmarked URL carrying the retired view param", () => {
    // `?view=journey` was a live, shareable URL while the correlation grouping shipped, so a
    // bookmark or a ticket link can still carry it. The param is no longer read: the screen renders
    // the chronological timeline, and the next URL write drops the stale key rather than echoing it.
    searchParamsStr = `actorType=api_key&actorId=${ENTRY.actorId}&view=journey`;
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);

    // The day divider is what distinguishes the surviving mode: the row rendered under BOTH
    // groupings, so asserting the row alone would stay green over a restored correlation grouping.
    expect(
      screen.getByText(dateTimeProvider.formatIsoToLongDate(ENTRY.occurredOn)),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByTestId(`audit-timeline__row-${ENTRY.id}`));
    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock.mock.calls[0][0]).not.toContain("view=");
  });

  it("writes the toggled sort direction rather than the current one", () => {
    // `commit()` takes the direction positionally, and `setDirection` passing the CURRENT `direction`
    // instead of the toggled `next` type-checks cleanly while leaving the header inert. From the DESC
    // default that bug writes no `dir` at all, because DESC is the omitted default.
    timelineState = stateWith({});
    render(<AuditInvestigationScreen />);

    fireEvent.click(screen.getByRole("button", { name: "Time" }));

    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock.mock.calls[0][0]).toContain("dir=asc");
  });

  it("renders an error panel with a retry action", () => {
    timelineState = stateWith({
      state: ViewStatus.ERROR,
      entries: [],
      problem: {
        type: "about:blank",
        title: "Error de carga",
        status: 500,
        detail: "boom",
        instance: "i",
        "correlation-id": "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
      },
    });
    render(<AuditInvestigationScreen />);
    expect(screen.getByText("Error de carga")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /retry/i })).toBeInTheDocument();
  });
});
