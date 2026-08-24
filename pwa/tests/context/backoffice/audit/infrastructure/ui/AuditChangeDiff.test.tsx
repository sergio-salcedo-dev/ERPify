import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditChangeDiff } from "@/context/backoffice/audit/infrastructure/ui/AuditChangeDiff";
import type { AuditChanges } from "@/context/backoffice/audit/domain/AuditChange";

function renderDiff(changes: AuditChanges) {
  return render(<AuditChangeDiff changes={changes} testId="diff" />);
}

describe("AuditChangeDiff", () => {
  it("renders a changed field as old → new with both values shown", () => {
    renderDiff({ name: { old: "BBVA", new: "BBVA S.A." } });

    expect(screen.getByText("BBVA")).toBeInTheDocument();
    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
    expect(screen.getByText("Changed")).toBeInTheDocument();
  });

  it("renders untrusted values as escaped text and never executes markup", () => {
    const payload = "<script>alert(1)</script>";

    const { container } = renderDiff({ name: { old: "BBVA", new: payload } });

    expect(screen.getByText(payload)).toBeInTheDocument();
    expect(container.querySelector("script")).toBeNull();
  });

  it("labels added, removed and changed on a non-colour text channel", () => {
    renderDiff({
      swift: { old: null, new: "BBVAESMM" },
      logo: { old: "media-1", new: null },
      name: { old: "BBVA", new: "BBVA S.A." },
    });

    expect(screen.getByText("Added")).toBeInTheDocument();
    expect(screen.getByText("Removed")).toBeInTheDocument();
    expect(screen.getByText("Changed")).toBeInTheDocument();
  });

  it("renders a never-populated field in the neutral state, distinct from an empty-string value", () => {
    renderDiff({
      cleared: { old: null, new: null },
      blank: { old: "", new: "x" },
    });

    expect(screen.getByTestId("diff__field-cleared")).toHaveAttribute("data-kind", "empty");
    expect(screen.getByText("Not set")).toBeInTheDocument();
    expect(screen.getByText("— (empty)")).toBeInTheDocument();
    expect(screen.getByText("(empty string)")).toBeInTheDocument();
    expect(screen.getByText("x")).toBeInTheDocument();
  });

  it("never labels a never-populated field as a modification", () => {
    renderDiff({ bic: { old: null, new: null } });

    expect(screen.queryByText("Changed")).not.toBeInTheDocument();
    expect(screen.queryByText("Added")).not.toBeInTheDocument();
    expect(screen.queryByText("Removed")).not.toBeInTheDocument();
    expect(screen.getByText("Not set")).toBeInTheDocument();
  });

  it("renders a never-populated field as a single sentinel, never as old → new", () => {
    renderDiff({ bic: { old: null, new: null } });

    expect(screen.getAllByText("— (empty)")).toHaveLength(1);
  });

  it("omits the type hint for a field that never carried a value", () => {
    renderDiff({
      bic: { old: null, new: null },
      name: { old: "BBVA", new: "BBVA S.A." },
    });

    expect(screen.getByTestId("diff__field-name")).toHaveTextContent("text");
    expect(screen.getByTestId("diff__field-bic")).not.toHaveTextContent("·");
  });

  it("renders a never-populated field alongside real changes instead of dropping it", () => {
    renderDiff({
      name: { old: "BBVA", new: "BBVA S.A." },
      bic: { old: null, new: null },
    });

    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
    expect(screen.getByText("Changed")).toBeInTheDocument();
    expect(screen.getByTestId("diff__field-bic")).toBeInTheDocument();
  });

  it("renders an all-empty record as its captured fields, not as a single summary line", () => {
    renderDiff({
      bic: { old: null, new: null },
      alias: { old: null, new: null },
    });

    expect(screen.getByTestId("diff__field-bic")).toBeInTheDocument();
    expect(screen.getByTestId("diff__field-alias")).toBeInTheDocument();
    expect(screen.queryByText("No changes recorded")).not.toBeInTheDocument();
  });

  it("claims no write direction: an all-added diff is never labelled a CREATE snapshot", () => {
    renderDiff({
      swift: { old: null, new: "BBVAESMM" },
      logo: { old: null, new: "media-1" },
    });

    expect(screen.queryByText("Initial state")).not.toBeInTheDocument();
    expect(screen.queryByTestId("diff__snapshot")).not.toBeInTheDocument();
  });

  it("claims no write direction: an all-removed diff is never labelled a DELETE snapshot", () => {
    renderDiff({ name: { old: "BBVA", new: null } });

    expect(screen.queryByText("Final state before deletion")).not.toBeInTheDocument();
    expect(screen.queryByTestId("diff__snapshot")).not.toBeInTheDocument();
  });

  it("keeps never-populated fields out of the collapsed window so populated ones stay visible", () => {
    const changes: AuditChanges = {
      bic: { old: null, new: null },
      alias: { old: null, new: null },
      ...Object.fromEntries(
        Array.from({ length: 8 }, (_unused, index) => [
          `field${index}`,
          { old: `before${index}`, new: `after${index}` },
        ]),
      ),
    };

    renderDiff(changes);

    // Ten rows collapse to six. Declared first, the two empty fields would take two of those slots
    // and push `after4`/`after5` out of sight — the regression this ordering exists to prevent.
    for (let index = 0; index < 6; index += 1) {
      expect(screen.getByText(`after${index}`)).toBeInTheDocument();
    }
    expect(screen.queryByTestId("diff__field-bic")).not.toBeInTheDocument();
    expect(screen.queryByTestId("diff__field-alias")).not.toBeInTheDocument();
  });

  it("shows a sealed sentinel for a crypto-shredded value and never the ciphertext", () => {
    renderDiff({ holderName: { old: null, new: { __enc__: "c2VjcmV0LWNpcGhlcnRleHQ" } } });

    expect(screen.getByText("encrypted (not available)")).toBeInTheDocument();
    expect(screen.queryByText(/c2VjcmV0/)).not.toBeInTheDocument();
  });

  it("renders an empty-changes map as «No changes recorded»", () => {
    renderDiff({});

    expect(screen.getByText("No changes recorded")).toBeInTheDocument();
  });

  it("collapses a large diff behind a reveal toggle", () => {
    const changes: AuditChanges = Object.fromEntries(
      Array.from({ length: 9 }, (_unused, index) => [
        `field${index}`,
        { old: `before${index}`, new: `after${index}` },
      ]),
    );

    renderDiff(changes);

    expect(screen.getByTestId("diff__toggle")).toHaveTextContent("Show 3 more fields");
  });

  it("reveals every hidden field and flips the toggle to «Show less» when expanded", () => {
    const changes: AuditChanges = Object.fromEntries(
      Array.from({ length: 9 }, (_unused, index) => [
        `field${index}`,
        { old: `before${index}`, new: `after${index}` },
      ]),
    );

    renderDiff(changes);
    expect(screen.queryByText("after8")).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId("diff__toggle"));

    expect(screen.getByTestId("diff__toggle")).toHaveTextContent("Show less");
    expect(screen.getByText("after8")).toBeInTheDocument();
  });

  it("wraps a value past the length threshold in a truncation container with a copy affordance", () => {
    const longValue = "Banco Bilbao Vizcaya Argentaria Sociedad Anónima Unipersonal";

    renderDiff({ legalName: { old: null, new: longValue } });

    expect(screen.getByText(longValue)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copy value/i })).toBeInTheDocument();
  });

  it("renders without a testId, emitting no data-testid attributes", () => {
    const { container } = render(
      <AuditChangeDiff changes={{ name: { old: "BBVA", new: "BBVA S.A." } }} />,
    );

    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
    expect(container.querySelector("[data-testid]")).toBeNull();
  });
});
