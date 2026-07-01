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

  it("distinguishes a null value «— (vacío)» from an empty string «(cadena vacía)»", () => {
    renderDiff({
      cleared: { old: null, new: null },
      blank: { old: "", new: "x" },
    });

    expect(screen.getAllByText("— (vacío)").length).toBeGreaterThan(0);
    expect(screen.getByText("(cadena vacía)")).toBeInTheDocument();
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
