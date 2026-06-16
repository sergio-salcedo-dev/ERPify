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
import type {
  CrudRepository,
  ResourceSearchPage,
} from "@/context/shared/resource/domain/CrudRepository";
import type { ResourceSearchNavigator } from "@/context/shared/resource/domain/ResourceSearchNavigator";

interface Row {
  id: string;
  name: string;
}
type RowInput = { name: string };
type RowFilter = Record<string, never>;

const REPO_KEY = "TestResourceListRepository";
const NAV_KEY = "TestResourceListNavigator";
const DIVERGENT_REPO_KEY = "DivergentEnvelopeRepository";
const DIVERGENT_NAV_KEY = "DivergentEnvelopeNavigator";

/**
 * A server-contract violation: the envelope flags more pages (`hasNext`/
 * `hasPrev`) yet carries no cursor links. The hook must gate the affordance on
 * the link, so the prev/next controls stay disabled instead of no-opping.
 */
class DivergentEnvelopeRepository implements CrudRepository<Row, RowInput> {
  async search(): Promise<ResourceSearchPage<Row>> {
    return {
      items: [{ id: "id-0", name: "n0" }],
      hasNext: true,
      hasPrev: true,
      count: 1,
      links: { next: null, prev: null },
    };
  }
  find(): Promise<Row> {
    throw new Error("not used");
  }
  create(): Promise<Row> {
    throw new Error("not used");
  }
  update(): Promise<Row> {
    throw new Error("not used");
  }
  delete(): Promise<void> {
    throw new Error("not used");
  }
}

class UnusedNavigator implements ResourceSearchNavigator<Row> {
  async follow(): Promise<ResourceSearchPage<Row>> {
    throw new Error("navigation must not happen without a link");
  }
}

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
  container.bind(DIVERGENT_REPO_KEY).toConstantValue(new DivergentEnvelopeRepository());
  container.bind(DIVERGENT_NAV_KEY).toConstantValue(new UnusedNavigator());
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

function DivergentHarness() {
  const query = useQueryState<RowFilter>({
    emptyFilter: {},
    defaultSort: null,
    defaultPageSize: 3,
  });
  const list = useResourceList<Row, RowFilter, RowInput>({
    repositoryKey: DIVERGENT_REPO_KEY,
    navigatorKey: DIVERGENT_NAV_KEY,
    query,
    toCriteria: (_filter, sort, limit) => ({ filters: [], sort, limit }),
    hasActiveFilter: () => false,
    getLabel: (row) => row.name,
    entitySingular: "row",
    entityPlural: "rows",
  });
  return (
    <div>
      <span data-testid="d-state">{list.state}</span>
      <span data-testid="d-has-prev">{String(list.paginationActions.hasPrev)}</span>
      <span data-testid="d-has-next">{String(list.paginationActions.hasNext)}</span>
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

  it("keeps prev/next disabled when the envelope flags a page but carries no link", async () => {
    render(<DivergentHarness />);
    await waitFor(() => expect(screen.getByTestId("d-state").textContent).toBe("ready"));

    // hasNext/hasPrev are true on the envelope, but both links are null — the
    // affordance follows the link, so the controls must read disabled.
    expect(screen.getByTestId("d-has-next").textContent).toBe("false");
    expect(screen.getByTestId("d-has-prev").textContent).toBe("false");
  });
});
