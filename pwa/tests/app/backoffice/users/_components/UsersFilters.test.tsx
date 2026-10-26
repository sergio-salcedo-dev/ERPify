import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { UsersFilters } from "@/app/backoffice/users/_components/UsersFilters";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  type UsersFilter,
  type UsersSort,
} from "@/app/backoffice/users/_lib/usersFilterSort";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

interface RenderOptions {
  filter?: UsersFilter;
  sort?: UsersSort;
  defaultOpen?: boolean;
  leading?: ReactNode;
}

function renderFilters(options: RenderOptions = {}) {
  const onFilterChange = vi.fn();
  const onSortChange = vi.fn();
  const onReset = vi.fn();
  const props = {
    filter: options.filter ?? EMPTY_FILTER,
    sort: options.sort === undefined ? DEFAULT_SORT : options.sort,
    onFilterChange,
    onSortChange,
    onReset,
    defaultOpen: options.defaultOpen,
    leading: options.leading,
  };
  const utils = render(<UsersFilters {...props} />);
  return { ...utils, props, onFilterChange, onSortChange, onReset };
}

function emailInput(): HTMLInputElement {
  return screen.getByTestId("users-filters__email") as HTMLInputElement;
}

describe("UsersFilters — email search", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("propagates a typed email only once the debounce elapses, keeping the other fields", () => {
    const filter = { ...EMPTY_FILTER, status: UserStatus.ACTIVE };
    const { onFilterChange } = renderFilters({ filter });

    fireEvent.change(emailInput(), { target: { value: "ada@" } });
    expect(emailInput().value).toBe("ada@");

    act(() => {
      vi.advanceTimersByTime(299);
    });
    expect(onFilterChange).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(onFilterChange).toHaveBeenCalledTimes(1);
    expect(onFilterChange).toHaveBeenCalledWith({ email: "ada@", status: UserStatus.ACTIVE });
  });

  it("syncs the input down when the parent changes the email, without pushing it back up", () => {
    const { rerender, props, onFilterChange } = renderFilters({
      filter: { ...EMPTY_FILTER, email: "old@example.com" },
    });
    expect(emailInput().value).toBe("old@example.com");

    rerender(<UsersFilters {...props} filter={{ ...EMPTY_FILTER, email: "new@example.com" }} />);
    expect(emailInput().value).toBe("new@example.com");

    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalled();
  });

  it("Clear all empties a pending email and delegates the reset to the parent", () => {
    const { onFilterChange, onReset } = renderFilters({
      filter: { ...EMPTY_FILTER, status: UserStatus.SUSPENDED },
      defaultOpen: true,
    });

    fireEvent.change(emailInput(), { target: { value: "pending@" } });
    fireEvent.click(screen.getByTestId("users-filters__reset"));

    expect(onReset).toHaveBeenCalledTimes(1);
    expect(emailInput().value).toBe("");
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).not.toHaveBeenCalledWith(expect.objectContaining({ email: "pending@" }));
  });
});

describe("UsersFilters — status and sort", () => {
  it("changing the status forwards it with the current email preserved", () => {
    const { onFilterChange } = renderFilters({
      filter: { email: "ada@example.com", status: "" },
      defaultOpen: true,
    });

    fireEvent.change(screen.getByTestId("users-filters__status"), {
      target: { value: UserStatus.SUSPENDED },
    });

    expect(onFilterChange).toHaveBeenCalledWith({
      email: "ada@example.com",
      status: UserStatus.SUSPENDED,
    });
  });

  it("choosing None as the sort column clears the sort", () => {
    const { onSortChange } = renderFilters({ defaultOpen: true });

    fireEvent.change(screen.getByTestId("users-filters__sort-by"), {
      target: { value: "__none__" },
    });

    expect(onSortChange).toHaveBeenCalledWith(null);
  });

  it("choosing a column with no current sort starts it ascending", () => {
    const { onSortChange } = renderFilters({ sort: null, defaultOpen: true });

    fireEvent.change(screen.getByTestId("users-filters__sort-by"), {
      target: { value: "createdAt" },
    });

    expect(onSortChange).toHaveBeenCalledWith({
      columnId: "createdAt",
      direction: SortDirection.ASC,
    });
  });

  it("choosing a column keeps the current direction", () => {
    const { onSortChange } = renderFilters({
      sort: { columnId: "email", direction: SortDirection.DESC },
      defaultOpen: true,
    });

    fireEvent.change(screen.getByTestId("users-filters__sort-by"), {
      target: { value: "status" },
    });

    expect(onSortChange).toHaveBeenCalledWith({
      columnId: "status",
      direction: SortDirection.DESC,
    });
  });

  it("changing the direction keeps the sorted column", () => {
    const { onSortChange } = renderFilters({ defaultOpen: true });

    fireEvent.change(screen.getByTestId("users-filters__sort-direction"), {
      target: { value: SortDirection.DESC },
    });

    expect(onSortChange).toHaveBeenCalledWith({
      columnId: "email",
      direction: SortDirection.DESC,
    });
  });

  it("the direction control is disabled and inert while nothing is sorted", () => {
    const { onSortChange } = renderFilters({ sort: null, defaultOpen: true });
    const direction = screen.getByTestId("users-filters__sort-direction") as HTMLSelectElement;

    expect(direction).toBeDisabled();
    fireEvent.change(direction, { target: { value: SortDirection.DESC } });

    expect(onSortChange).not.toHaveBeenCalled();
  });
});

describe("UsersFilters — panel", () => {
  it("starts collapsed with the default query and expands on toggle", () => {
    renderFilters();
    const section = screen.getByTestId("users-filters");
    const panel = screen.getByTestId("users-filters__panel");
    const toggle = screen.getByTestId("users-filters__toggle");

    expect(section).toHaveAttribute("data-open", "false");
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(panel).toHaveAttribute("aria-hidden", "true");
    expect(panel).toHaveAttribute("inert");
    expect(panel.style.gridTemplateRows).toBe("0fr");

    fireEvent.click(toggle);

    expect(section).toHaveAttribute("data-open", "true");
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(panel).toHaveAttribute("aria-hidden", "false");
    expect(panel).not.toHaveAttribute("inert");
    expect(panel.style.gridTemplateRows).toBe("1fr");

    fireEvent.click(toggle);
    expect(section).toHaveAttribute("data-open", "false");
  });

  it("opens by itself when a panel filter or a non-default sort is active", () => {
    const { unmount } = renderFilters({ filter: { ...EMPTY_FILTER, status: UserStatus.INVITED } });
    expect(screen.getByTestId("users-filters")).toHaveAttribute("data-open", "true");
    unmount();

    renderFilters({ sort: { columnId: "createdAt", direction: SortDirection.DESC } });
    expect(screen.getByTestId("users-filters")).toHaveAttribute("data-open", "true");
  });

  it("names the active panel filter count on the toggle only when one is set", () => {
    const { unmount } = renderFilters();
    const plain = screen.getByTestId("users-filters__toggle");
    expect(plain).toHaveAttribute("aria-label", "Filters");
    expect(screen.queryByTestId("users-filters__count")).toBeNull();
    unmount();

    renderFilters({ filter: { ...EMPTY_FILTER, status: UserStatus.ACTIVE } });
    expect(screen.getByTestId("users-filters__toggle")).toHaveAttribute(
      "aria-label",
      "Filters, 1 in this panel",
    );
    expect(screen.getByTestId("users-filters__count")).toHaveTextContent("1");
  });

  it("offers Clear all only when something differs from the defaults", () => {
    const { unmount } = renderFilters({ defaultOpen: true });
    expect(screen.queryByTestId("users-filters__reset")).toBeNull();
    unmount();

    renderFilters({ sort: null, defaultOpen: true });
    expect(screen.getByTestId("users-filters__reset")).toBeInTheDocument();
  });

  it("renders the leading slot only when one is provided", () => {
    const { unmount, container } = renderFilters();
    expect(container.querySelector(".users-filters__leading")).toBeNull();
    unmount();

    renderFilters({ leading: <span data-testid="users-filters-test__leading">view</span> });
    expect(screen.getByTestId("users-filters-test__leading")).toBeInTheDocument();
  });
});
