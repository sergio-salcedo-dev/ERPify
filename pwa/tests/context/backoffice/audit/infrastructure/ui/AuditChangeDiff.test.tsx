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
    expect(screen.getByText("Modificado")).toBeInTheDocument();
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

    expect(screen.getByText("Añadido")).toBeInTheDocument();
    expect(screen.getByText("Eliminado")).toBeInTheDocument();
    expect(screen.getByText("Modificado")).toBeInTheDocument();
  });

  it("hides a no-op field (both sides null) while keeping an empty-string value visible", () => {
    renderDiff({
      cleared: { old: null, new: null },
      blank: { old: "", new: "x" },
    });

    expect(screen.queryByTestId("diff__field-cleared")).not.toBeInTheDocument();
    expect(screen.queryByText("— (vacío)")).not.toBeInTheDocument();
    expect(screen.getByText("(cadena vacía)")).toBeInTheDocument();
    expect(screen.getByText("x")).toBeInTheDocument();
  });

  it("hides a no-op field among real changes and renders the rest", () => {
    renderDiff({
      name: { old: "BBVA", new: "BBVA S.A." },
      bic: { old: null, new: null },
    });

    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
    expect(screen.getByText("Modificado")).toBeInTheDocument();
    expect(screen.queryByTestId("diff__field-bic")).not.toBeInTheDocument();
  });

  it("keeps the DELETE snapshot header when a no-op field sits among removed fields", () => {
    renderDiff({
      name: { old: "BBVA", new: null },
      bic: { old: null, new: null },
    });

    expect(screen.getByText("Estado final antes del borrado")).toBeInTheDocument();
    expect(screen.queryByTestId("diff__field-bic")).not.toBeInTheDocument();
  });

  it("keeps the CREATE snapshot header when a no-op field sits among added fields", () => {
    renderDiff({
      name: { old: null, new: "BBVA" },
      bic: { old: null, new: null },
    });

    expect(screen.getByText("Estado inicial")).toBeInTheDocument();
    expect(screen.queryByTestId("diff__field-bic")).not.toBeInTheDocument();
  });

  it("reads an all-empty snapshot as «Registro sin campos con valor», not «Sin cambios registrados»", () => {
    renderDiff({
      bic: { old: null, new: null },
      alias: { old: null, new: null },
    });

    expect(screen.getByText("Registro sin campos con valor")).toBeInTheDocument();
    expect(screen.queryByText("Sin cambios registrados")).not.toBeInTheDocument();
  });

  it("names a CREATE snapshot (all added) and a DELETE snapshot (all removed)", () => {
    const created = renderDiff({
      name: { old: null, new: "BBVA" },
      swift: { old: null, new: "BBVAESMM" },
    });
    expect(screen.getByText("Estado inicial")).toBeInTheDocument();
    created.unmount();

    renderDiff({ name: { old: "BBVA", new: null } });
    expect(screen.getByText("Estado final antes del borrado")).toBeInTheDocument();
  });

  it("shows a sealed sentinel for a crypto-shredded value and never the ciphertext", () => {
    renderDiff({ holderName: { old: null, new: { __enc__: "c2VjcmV0LWNpcGhlcnRleHQ" } } });

    expect(screen.getByText("cifrado (no disponible)")).toBeInTheDocument();
    expect(screen.queryByText(/c2VjcmV0/)).not.toBeInTheDocument();
  });

  it("renders an empty-changes map as «Sin cambios registrados»", () => {
    renderDiff({});

    expect(screen.getByText("Sin cambios registrados")).toBeInTheDocument();
  });

  it("collapses a large diff behind a reveal toggle", () => {
    const changes: AuditChanges = Object.fromEntries(
      Array.from({ length: 9 }, (_unused, index) => [
        `field${index}`,
        { old: `before${index}`, new: `after${index}` },
      ]),
    );

    renderDiff(changes);

    expect(screen.getByTestId("diff__toggle")).toHaveTextContent("Ver 3 campos más");
  });

  it("reveals every hidden field and flips the toggle to «Ver menos» when expanded", () => {
    const changes: AuditChanges = Object.fromEntries(
      Array.from({ length: 9 }, (_unused, index) => [
        `field${index}`,
        { old: `before${index}`, new: `after${index}` },
      ]),
    );

    renderDiff(changes);
    expect(screen.queryByText("after8")).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId("diff__toggle"));

    expect(screen.getByTestId("diff__toggle")).toHaveTextContent("Ver menos");
    expect(screen.getByText("after8")).toBeInTheDocument();
  });

  it("wraps a value past the length threshold in a truncation container with a copy affordance", () => {
    const longValue = "Banco Bilbao Vizcaya Argentaria Sociedad Anónima Unipersonal";

    renderDiff({ legalName: { old: null, new: longValue } });

    expect(screen.getByText(longValue)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copiar valor/i })).toBeInTheDocument();
  });

  it("renders without a testId, emitting no data-testid attributes", () => {
    const { container } = render(
      <AuditChangeDiff changes={{ name: { old: "BBVA", new: "BBVA S.A." } }} />,
    );

    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
    expect(container.querySelector("[data-testid]")).toBeNull();
  });
});
