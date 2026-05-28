import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { RecordSheet } from "@/components/erpify/RecordSheet";

describe("RecordSheet", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("renders title and subtitle when open", () => {
    render(
      <RecordSheet open={true} onOpenChange={() => {}} title="Bank #123" subtitle="Active">
        <p>body content</p>
      </RecordSheet>,
    );
    expect(screen.getByText("Bank #123")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();
    expect(screen.getByText("body content")).toBeInTheDocument();
  });

  it("renders the footer slot when supplied", () => {
    render(
      <RecordSheet open={true} onOpenChange={() => {}} title="Bank" footer={<button>Save</button>}>
        <p>body</p>
      </RecordSheet>,
    );
    expect(screen.getByRole("button", { name: "Save" })).toBeInTheDocument();
  });

  it("calls onOpenChange(false) when close button is clicked and not dirty", () => {
    const onOpenChange = vi.fn();
    render(
      <RecordSheet open={true} onOpenChange={onOpenChange} title="Bank">
        <p>body</p>
      </RecordSheet>,
    );
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it("prompts for confirmation when dirty and intercepts close on cancel", () => {
    const onOpenChange = vi.fn();
    const confirm = vi.spyOn(globalThis, "confirm").mockReturnValue(false);
    render(
      <RecordSheet
        open={true}
        onOpenChange={onOpenChange}
        title="Bank"
        dirty
        confirmCloseMessage="Discard changes?"
      >
        <p>body</p>
      </RecordSheet>,
    );
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(confirm).toHaveBeenCalledWith("Discard changes?");
    expect(onOpenChange).not.toHaveBeenCalled();
  });

  it("allows close when dirty + user confirms", () => {
    const onOpenChange = vi.fn();
    vi.spyOn(globalThis, "confirm").mockReturnValue(true);
    render(
      <RecordSheet open={true} onOpenChange={onOpenChange} title="Bank" dirty>
        <p>body</p>
      </RecordSheet>,
    );
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
