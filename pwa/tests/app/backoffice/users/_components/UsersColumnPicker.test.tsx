import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { UsersColumnPicker } from "@/app/backoffice/users/_components/UsersColumnPicker";

describe("UsersColumnPicker", () => {
  it("opens without throwing and lists the toggleable columns", () => {
    render(<UsersColumnPicker visible={["email", "roles"]} onChange={vi.fn()} testId="cols" />);

    fireEvent.click(screen.getByTestId("cols"));

    // The group label and a toggleable item render only once the menu opens; a
    // GroupLabel outside its <Menu.Group> throws "MenuGroupContext is missing".
    expect(screen.getByText("Visible columns")).toBeInTheDocument();
    expect(screen.getByText("Status")).toBeInTheDocument();
  });

  it("toggles a column off when an active checkbox item is selected", () => {
    const onChange = vi.fn();
    render(
      <UsersColumnPicker
        visible={["email", "roles", "status"]}
        onChange={onChange}
        testId="cols"
      />,
    );

    fireEvent.click(screen.getByTestId("cols"));
    fireEvent.click(screen.getByTestId("users-columns__status"));

    expect(onChange).toHaveBeenCalledWith(["email", "roles"]);
  });
});
