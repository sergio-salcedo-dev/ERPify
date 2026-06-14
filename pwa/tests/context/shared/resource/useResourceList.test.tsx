import { describe, it, expect, beforeAll } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import {
  InMemoryCrudRepository,
  type InMemoryEntityConfig,
} from "@/context/shared/resource/infrastructure/InMemoryCrudRepository";
import { InMemoryResourceNavigator } from "@/context/shared/resource/infrastructure/InMemoryResourceNavigator";
import { useQueryState } from "@/context/shared/resource/application/createQueryState";
import { useResourceList } from "@/context/shared/resource/application/useResourceList";

interface Row {
  id: string;
  name: string;
}
type RowInput = { name: string };
type RowFilter = Record<string, never>;

const REPO_KEY = "TestResourceListRepository";
const NAV_KEY = "TestResourceListNavigator";

const config: InMemoryEntityConfig<Row, RowInput> = {
  matchesFilter: () => true,
  compare: () => (a, b) => a.name.localeCompare(b.name),
  fromInput: (input, id) => ({ id, name: input.name }),
  applyInput: (row, input) => ({ ...row, name: input.name }),
};

beforeAll(() => {
  const seed: Row[] = Array.from({ length: 7 }, (_, i) => ({ id: `id-${i}`, name: `n${i}` }));
  const repo = new InMemoryCrudRepository<Row, RowInput>(
    seed,
    config,
    () => "new-id",
    () => "2026-01-01T00:00:00.000Z",
  );
  container.bind(REPO_KEY).toConstantValue(repo);
  container.bind(NAV_KEY).toConstantValue(new InMemoryResourceNavigator<Row, RowInput>(repo));
});

function Harness() {
  const query = useQueryState<RowFilter>({
    emptyFilter: {},
    defaultSort: null,
    defaultPageSize: 3,
  });
  const list = useResourceList<Row, RowFilter, RowInput>({
    repositoryKey: REPO_KEY,
    navigatorKey: NAV_KEY,
    query,
    toCriteria: (_filter, sort, limit) => ({ filters: [], sort, limit }),
    hasActiveFilter: () => false,
    getLabel: (row) => row.name,
    entitySingular: "row",
    entityPlural: "rows",
  });
  return (
    <div>
      <span data-testid="state">{list.state}</span>
      <span data-testid="ids">{list.items.map((r) => r.id).join(",")}</span>
      <span data-testid="selected">{[...list.selectedIds].join(",")}</span>
      <button type="button" data-testid="select-0" onClick={() => list.toggleSelect("id-0")}>
        select id-0
      </button>
      <button type="button" data-testid="mark-0" onClick={() => list.markDeleted("id-0")}>
        mark id-0
      </button>
      <button type="button" data-testid="mark-1" onClick={() => list.markDeleted("id-1")}>
        mark id-1
      </button>
      <button
        type="button"
        data-testid="advance"
        onClick={() => {
          const link = list.pagination?.links.next;
          if (link) list.navigateTo(link);
        }}
      >
        next
      </button>
    </div>
  );
}

describe("useResourceList", () => {
  it("loads the first page then advances via the next link", async () => {
    render(<Harness />);

    await waitFor(() => expect(screen.getByTestId("state").textContent).toBe("ready"));
    expect(screen.getByTestId("ids").textContent).toBe("id-0,id-1,id-2");

    fireEvent.click(screen.getByTestId("advance"));
    await waitFor(() => expect(screen.getByTestId("ids").textContent).toBe("id-3,id-4,id-5"));
  });

  it("markDeleted drops the id from the current selection", async () => {
    render(<Harness />);
    await waitFor(() => expect(screen.getByTestId("state").textContent).toBe("ready"));

    fireEvent.click(screen.getByTestId("select-0"));
    await waitFor(() => expect(screen.getByTestId("selected").textContent).toBe("id-0"));

    fireEvent.click(screen.getByTestId("mark-0"));
    await waitFor(() => expect(screen.getByTestId("selected").textContent).toBe(""));
  });

  it("markDeleted is a no-op for an id that is not selected", async () => {
    render(<Harness />);
    await waitFor(() => expect(screen.getByTestId("state").textContent).toBe("ready"));

    fireEvent.click(screen.getByTestId("select-0"));
    await waitFor(() => expect(screen.getByTestId("selected").textContent).toBe("id-0"));

    // id-1 was never selected — tombstoning it must not disturb the selection.
    fireEvent.click(screen.getByTestId("mark-1"));
    expect(screen.getByTestId("selected").textContent).toBe("id-0");
  });
});
