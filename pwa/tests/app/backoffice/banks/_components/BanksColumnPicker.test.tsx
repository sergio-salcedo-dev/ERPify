import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksColumnPicker } from "@/app/backoffice/banks/_components/BanksColumnPicker";

describe("BanksColumnPicker", () => {
  it("opens without throwing and lists the toggleable columns", () => {
    render(
      <BanksColumnPicker
        visible={["shortName", "name", "status"]}
        onChange={vi.fn()}
        testId="cols"
      />,
    );

    fireEvent.click(screen.getByTestId("cols"));

    // The group label and a toggleable item render only once the menu opens; a
    // GroupLabel outside its <Menu.Group> throws "MenuGroupContext is missing".
    expect(screen.getByText("Visible columns")).toBeInTheDocument();
    expect(screen.getByText("Accounts")).toBeInTheDocument();
  });

  it("toggles a column off when an active checkbox item is selected", () => {
    const onChange = vi.fn();
    render(
      <BanksColumnPicker
        visible={["shortName", "name", "status"]}
        onChange={onChange}
        testId="cols"
      />,
    );

    fireEvent.click(screen.getByTestId("cols"));
    fireEvent.click(screen.getByTestId("banks-columns__status"));

    expect(onChange).toHaveBeenCalledWith(["shortName", "name"]);
  });
});
