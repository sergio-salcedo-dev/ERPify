import { describe, expect, it, vi, beforeEach } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { useAuditTimeline } from "@/context/backoffice/audit/application/useAuditTimeline";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type {
  AuditTimelineCriteria,
  AuditTimelinePage,
} from "@/context/backoffice/audit/domain/AuditTimelineRepository";

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: vi.fn() },
}));

const CRITERIA: AuditTimelineCriteria = {
  filters: [],
  sort: { field: "occurredOn", direction: "desc" },
  limit: 25,
};

function pageOf(
  action: string,
  links: { next: string | null; prev: string | null },
): AuditTimelinePage {
  return {
    entries: [
      {
        id: action,
        occurredOn: "2026-06-12T12:00:00.000000+00:00",
        level: "activity",
        action,
        actorType: "anonymous",
        actorId: null,
        correlationId: "c",
        resourceType: null,
        resourceId: null,
        actorErased: false,
        resourceErased: false,
      },
    ],
    hasNext: links.next !== null,
    hasPrev: links.prev !== null,
    count: null,
    links,
  };
}

function bind(repo: unknown, nav: unknown): void {
  vi.mocked(container.get).mockImplementation((key) =>
    key === "BackOfficeAuditTimelineRepository" ? repo : nav,
  );
}

beforeEach(() => {
  vi.mocked(container.get).mockReset();
});

describe("useAuditTimeline", () => {
  it("loads the first page via the repository and reports READY", async () => {
    const repo = {
      search: vi.fn().mockResolvedValue(pageOf("FIRST", { next: "/next", prev: null })),
    };
    bind(repo, { follow: vi.fn() });

    const { result } = renderHook(() =>
      useAuditTimeline({ criteria: CRITERIA, hasActiveFilter: false }),
    );

    await waitFor(() => expect(result.current.state).toBe(ViewStatus.READY));
    expect(result.current.entries[0].action).toBe("FIRST");
    expect(result.current.paginationActions.hasNext).toBe(true);
    expect(result.current.paginationActions.hasPrev).toBe(false);
    expect(repo.search).toHaveBeenCalledWith(CRITERIA);
  });

  it("follows the next link verbatim through the navigator on goNext", async () => {
    const repo = {
      search: vi.fn().mockResolvedValue(pageOf("FIRST", { next: "/next", prev: null })),
    };
    const nav = {
      follow: vi.fn().mockResolvedValue(pageOf("SECOND", { next: null, prev: "/prev" })),
    };
    bind(repo, nav);

    const { result } = renderHook(() =>
      useAuditTimeline({ criteria: CRITERIA, hasActiveFilter: false }),
    );
    await waitFor(() => expect(result.current.entries[0].action).toBe("FIRST"));

    act(() => result.current.paginationActions.goNext());

    await waitFor(() => expect(result.current.entries[0].action).toBe("SECOND"));
    expect(nav.follow).toHaveBeenCalledWith("/next");
  });

  it("surfaces an HttpError problem and reports ERROR", async () => {
    const problem = {
      type: "about:blank",
      title: "Boom",
      status: 500,
      detail: "kaboom",
      instance: "i",
      "correlation-id": "c",
    };
    const repo = { search: vi.fn().mockRejectedValue(new HttpError(problem)) };
    bind(repo, { follow: vi.fn() });

    const { result } = renderHook(() =>
      useAuditTimeline({ criteria: CRITERIA, hasActiveFilter: false }),
    );

    await waitFor(() => expect(result.current.state).toBe(ViewStatus.ERROR));
    expect(result.current.problem?.title).toBe("Boom");
  });

  it("reads an empty unfiltered result as first-run EMPTY", async () => {
    const repo = {
      search: vi.fn().mockResolvedValue({
        entries: [],
        hasNext: false,
        hasPrev: false,
        count: null,
        links: { next: null, prev: null },
      }),
    };
    bind(repo, { follow: vi.fn() });

    const { result } = renderHook(() =>
      useAuditTimeline({ criteria: CRITERIA, hasActiveFilter: false }),
    );
    await waitFor(() => expect(result.current.state).toBe(ViewStatus.EMPTY));
  });
});
