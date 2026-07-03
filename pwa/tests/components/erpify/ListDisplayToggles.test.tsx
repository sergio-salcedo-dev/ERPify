import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { ListDisplayToggles } from "@/components/erpify/ListDisplayToggles";
import type { ResourceView } from "@/components/erpify";

/**
 * The column picker is a table-only affordance: cards render a fixed layout
 * that never reads the column preference. This primitive owns that gate so
 * every back-office list inherits it structurally — the regression that once
 * shipped the picker as a dead no-op in cards view (Banks, Users) can no
 * longer be re-introduced per entity. This single test therefore covers the
 * gate for all consumers.
 */

const VIEW_TOGGLE = <div data-testid="stub__view-toggle">view</div>;
const COLUMN_PICKER = <div data-testid="stub__columns">columns</div>;

function renderToggles(view: ResourceView, onDensityChange = vi.fn()) {
  return render(
    <ListDisplayToggles
      view={view}
      viewToggle={VIEW_TOGGLE}
      density="compact"
      onDensityChange={onDensityChange}
      columnPicker={COLUMN_PICKER}
      testIdPrefix="stub-list"
    />,
  );
}

describe("ListDisplayToggles", () => {
  it("renders the column picker in table view alongside the view and density toggles", () => {
    renderToggles("table");

    expect(screen.getByTestId("stub__view-toggle")).toBeInTheDocument();
    expect(screen.getByTestId("stub-list__density-toggle")).toBeInTheDocument();
    expect(screen.getByTestId("stub__columns")).toBeInTheDocument();
  });

  it("gates out the column picker in cards view while keeping the sibling toggles", () => {
    renderToggles("cards");

    // Cards render a fixed layout — the picker slot must not mount at all.
    expect(screen.queryByTestId("stub__columns")).toBeNull();
    expect(screen.getByTestId("stub__view-toggle")).toBeInTheDocument();
    expect(screen.getByTestId("stub-list__density-toggle")).toBeInTheDocument();
  });

  it("brings the picker back when the view switches from cards to table", () => {
    const { rerender } = renderToggles("cards");
    expect(screen.queryByTestId("stub__columns")).toBeNull();

    rerender(
      <ListDisplayToggles
        view="table"
        viewToggle={VIEW_TOGGLE}
        density="compact"
        onDensityChange={vi.fn()}
        columnPicker={COLUMN_PICKER}
        testIdPrefix="stub-list"
      />,
    );

    expect(screen.getByTestId("stub__columns")).toBeInTheDocument();
  });

  it("derives its wrapper and density testids from the prefix", () => {
    renderToggles("table");

    const wrapper = screen.getByTestId("stub-list__display-toggles");
    expect(wrapper).toHaveClass("list-display-toggles", "stub-list__display-toggles");
    expect(wrapper).toContainElement(screen.getByTestId("stub-list__density-toggle"));
  });

  it("forwards density changes from the owned density toggle", () => {
    const onDensityChange = vi.fn();
    renderToggles("table", onDensityChange);

    fireEvent.click(screen.getByTestId("stub-list__density-toggle-comfortable"));

    expect(onDensityChange).toHaveBeenCalledWith("comfortable");
  });
});
