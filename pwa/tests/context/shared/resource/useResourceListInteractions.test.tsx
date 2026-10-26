import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { useQueryState } from "@/context/shared/resource/application/createQueryState";
import { useResourceList } from "@/context/shared/resource/application/useResourceList";
import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

interface Row {
  id: string;
  name: string;
}
type RowFilter = Record<string, never>;

const REPO_KEY = "InteractionsResourceListRepository";
const NAV_KEY = "InteractionsResourceListNavigator";
const BULK_DELETE = "interactions-list__bulk-delete";

const search = vi.fn<() => Promise<ResourceSearchPage<Row>>>();
const follow = vi.fn<(link: string) => Promise<ResourceSearchPage<Row>>>();

function rows(...ids: string[]): Row[] {
  return ids.map((id) => ({ id, name: `name ${id}` }));
}

function page(items: Row[], prev: string | null, next: string | null): ResourceSearchPage<Row> {
  return {
    items,
    hasPrev: prev !== null,
    hasNext: next !== null,
    count: null,
    links: { prev, next },
  };
}

const FIRST = page(rows("a", "b", "c"), null, "L2");
const PAGES: Record<string, ResourceSearchPage<Row>> = {
  L1: FIRST,
  L2: page(rows("d", "e", "f"), "L1", "L3"),
  L3: page(rows("g"), "L2", null),
};

const BULK_PROBLEM: ProblemDetails = {
  type: "about:blank",
  title: "Not found",
  status: 404,
  detail: "gone",
  instance: "instance-bulk",
  "correlation-id": "correlation-bulk",
};

beforeAll(() => {
  container.bind(REPO_KEY).toConstantValue({
    search,
    find: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
  });
  container.bind(NAV_KEY).toConstantValue({ follow });
});

beforeEach(() => {
  search.mockReset();
  follow.mockReset();
  search.mockResolvedValue(FIRST);
  follow.mockImplementation(async (link) => PAGES[link]);
});

function Harness({ rowFocusTestIds }: Readonly<{ rowFocusTestIds?: (id: string) => string[] }>) {
  const query = useQueryState<RowFilter>({
    emptyFilter: {},
    defaultSort: null,
    defaultPageSize: 3,
  });
  const { listContainerRef, ...list } = useResourceList<Row, RowFilter, { name: string }>({
    repositoryKey: REPO_KEY,
    navigatorKey: NAV_KEY,
    query,
    toCriteria: (_filter, sort, limit) => ({ filters: [], sort, limit }),
    hasActiveFilter: () => false,
    getLabel: (row) => row.name,
    entitySingular: "row",
    entityPlural: "rows",
    rowFocusTestIds,
    bulkDeleteTestId: BULK_DELETE,
  });
  return (
    <div>
      <button type="button" data-testid="outside">
        outside
      </button>
      <div ref={listContainerRef} tabIndex={-1} data-testid="list">
        <span data-testid="state">{list.state}</span>
        <span data-testid="ids">{list.items.map((r) => r.id).join(",")}</span>
        <span data-testid="selected">{[...list.selectedIds].join(",")}</span>
        <span data-testid="announcement">{list.selectionAnnouncement}</span>
        {list.items.map((row) => (
          <div key={row.id}>
            <button type="button" data-testid={`row-${row.id}`}>
              {row.name}
            </button>
            <button
              type="button"
              data-testid={`toggle-${row.id}`}
              onClick={() => list.toggleSelect(row.id)}
            >
              toggle
            </button>
            <button
              type="button"
              data-testid={`delete-${row.id}`}
              onClick={() => list.deleteItem(row.id)}
            >
              delete
            </button>
          </div>
        ))}
        <button type="button" data-testid="prev" onClick={list.paginationActions.goPrev}>
          prev
        </button>
        <button type="button" data-testid="next" onClick={list.paginationActions.goNext}>
          next
        </button>
        <button
          type="button"
          data-testid="bulk-error"
          onClick={() => list.setDeleteError({ problem: BULK_PROBLEM, itemId: "a", scope: "bulk" })}
        >
          bulk error
        </button>
        <button
          type="button"
          data-testid="refresh"
          onClick={() => {
            void list.handleDeleteErrorRefresh();
          }}
        >
          refresh
        </button>
        <button
          type="button"
          data-testid="retry"
          onClick={() => {
            void list.reload();
          }}
        >
          retry
        </button>
        {list.selectedIds.size > 0 ? (
          <button type="button" data-testid={BULK_DELETE}>
            bulk delete
          </button>
        ) : null}
      </div>
    </div>
  );
}

function text(testId: string): string | null {
  return screen.getByTestId(testId).textContent;
}

async function renderReady(props: Parameters<typeof Harness>[0] = {}): Promise<void> {
  render(<Harness {...props} />);
  await waitFor(() => expect(text("state")).toBe("ready"));
  expect(text("ids")).toBe("a,b,c");
}

describe("useResourceList — previous page", () => {
  it("follows the server-issued prev link back to the earlier page", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("next"));
    await waitFor(() => expect(text("ids")).toBe("d,e,f"));

    fireEvent.click(screen.getByTestId("prev"));

    await waitFor(() => expect(text("ids")).toBe("a,b,c"));
    expect(follow).toHaveBeenLastCalledWith("L1");
  });

  it("does nothing on the first page, where the envelope carries no prev link", async () => {
    await renderReady();

    fireEvent.click(screen.getByTestId("prev"));

    expect(follow).not.toHaveBeenCalled();
    expect(text("ids")).toBe("a,b,c");
  });

  it("ignores a prev click while the next page is still loading", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("next"));
    await waitFor(() => expect(text("ids")).toBe("d,e,f"));

    // Both clicks land before the L3 load settles; the prev one would otherwise
    // re-follow this page's prev link and overrule the navigation in flight.
    fireEvent.click(screen.getByTestId("next"));
    fireEvent.click(screen.getByTestId("prev"));

    await waitFor(() => expect(text("ids")).toBe("g"));
    expect(follow.mock.calls.map(([link]) => link)).toEqual(["L2", "L3"]);
  });
});

describe("useResourceList — selection", () => {
  it("toggling a selected id deselects it", async () => {
    await renderReady();

    fireEvent.click(screen.getByTestId("toggle-b"));
    expect(text("selected")).toBe("b");

    fireEvent.click(screen.getByTestId("toggle-b"));
    expect(text("selected")).toBe("");
  });

  it("deleting a selected row also drops it from the selection", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("toggle-a"));
    fireEvent.click(screen.getByTestId("toggle-b"));
    expect(text("selected")).toBe("a,b");

    fireEvent.click(screen.getByTestId("delete-b"));

    expect(text("ids")).toBe("a,c");
    expect(text("selected")).toBe("a");
  });

  it("Escape on the list clears a non-empty selection", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("toggle-a"));
    expect(text("selected")).toBe("a");

    fireEvent.keyDown(screen.getByTestId("list"), { key: "Escape" });

    expect(text("selected")).toBe("");
  });

  it("announces the selected count, then that the selection was cleared", async () => {
    await renderReady();

    fireEvent.click(screen.getByTestId("toggle-a"));
    fireEvent.click(screen.getByTestId("toggle-c"));
    await waitFor(() => expect(text("announcement")).toBe("2 selected"));

    fireEvent.keyDown(screen.getByTestId("list"), { key: "Escape" });
    await waitFor(() => expect(text("announcement")).toBe("Selection cleared"));
  });
});

describe("useResourceList — focus after a single delete", () => {
  it("focuses the neighbour row when one of the configured testids matches it", async () => {
    await renderReady({ rowFocusTestIds: (id) => [`row-${id}`] });

    fireEvent.click(screen.getByTestId("delete-a"));

    expect(screen.getByTestId("row-b")).toHaveFocus();
  });

  it("falls back to the list container when no element carries the neighbour's testid", async () => {
    await renderReady({ rowFocusTestIds: (id) => [`missing-row-${id}`] });

    fireEvent.click(screen.getByTestId("delete-a"));

    expect(screen.getByTestId("list")).toHaveFocus();
  });
});

describe("useResourceList — focus after a bulk-error refresh", () => {
  it("focuses the bulk delete control when rows are still selected", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("toggle-a"));
    fireEvent.click(screen.getByTestId("bulk-error"));

    fireEvent.click(screen.getByTestId("refresh"));

    await waitFor(() => expect(screen.getByTestId(BULK_DELETE)).toHaveFocus());
  });

  it("focuses the list container when nothing is left selected", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("bulk-error"));
    screen.getByTestId("outside").focus();

    fireEvent.click(screen.getByTestId("refresh"));

    await waitFor(() => expect(screen.getByTestId("list")).toHaveFocus());
  });

  it("abandons the pending focus when the refresh fails, so a later retry leaves focus alone", async () => {
    await renderReady();
    fireEvent.click(screen.getByTestId("bulk-error"));
    const outside = screen.getByTestId("outside");
    outside.focus();

    search.mockRejectedValueOnce(new HttpError({ ...BULK_PROBLEM, status: 500 }));
    fireEvent.click(screen.getByTestId("refresh"));
    await waitFor(() => expect(text("state")).toBe("error"));
    expect(outside).toHaveFocus();

    fireEvent.click(screen.getByTestId("retry"));
    await waitFor(() => expect(text("state")).toBe("ready"));
    await act(async () => {});

    expect(outside).toHaveFocus();
  });
});
