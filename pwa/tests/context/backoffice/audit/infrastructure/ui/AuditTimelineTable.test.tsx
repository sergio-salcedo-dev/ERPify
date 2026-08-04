import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import {
  AuditTimelineTable,
  type AuditTimelineGroup,
} from "@/context/backoffice/audit/infrastructure/ui/AuditTimelineTable";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

function entryOf(patch: Partial<AuditEntry> & { id: string }): AuditEntry {
  return {
    occurredOn: "2026-06-12T12:00:00.000000+00:00",
    level: "activity",
    action: "ROUTE_BACKOFFICE_BANK_SEARCH",
    actorType: "anonymous",
    actorId: null,
    correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
    resourceType: null,
    resourceId: null,
    actorErased: false,
    resourceErased: false,
    ...patch,
  };
}

const SECURITY = entryOf({
  id: "a",
  level: "security",
  action: "ACCESS_DENIED",
  actorType: "api_key",
  actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
  resourceType: "BankAccount",
  resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
});
const ACTIVITY = entryOf({ id: "b" });

function groupsOf(entries: AuditEntry[]): AuditTimelineGroup[] {
  return [{ key: "12 de junio de 2026", header: "12 de junio de 2026", entries }];
}

function renderTable(overrides: Partial<Parameters<typeof AuditTimelineTable>[0]> = {}) {
  const props = {
    groups: groupsOf([SECURITY, ACTIVITY]),
    density: "compact" as const,
    direction: SortDirection.DESC,
    onToggleSort: vi.fn(),
    onRowActivate: vi.fn(),
    ...overrides,
  };
  render(<AuditTimelineTable {...props} />);
  return props;
}

describe("AuditTimelineTable", () => {
  it("renders each group under its header inside a rowgroup", () => {
    renderTable();
    expect(screen.getByText("12 de junio de 2026")).toBeInTheDocument();
    expect(screen.getByTestId("audit-timeline__row-a")).toBeInTheDocument();
    expect(screen.getByTestId("audit-timeline__row-b")).toBeInTheDocument();
  });

  it("renders the humanized action with its raw token preserved beneath", () => {
    renderTable();
    expect(screen.getByText("Acceso denegado")).toBeInTheDocument();
    expect(screen.getByText("ACCESS_DENIED")).toBeInTheDocument();
  });

  it("marks a security row with the lateral accent (a second channel, not danger)", () => {
    const { container } = render(
      <AuditTimelineTable
        groups={groupsOf([SECURITY])}
        density="compact"
        direction={SortDirection.DESC}
        onToggleSort={vi.fn()}
        onRowActivate={vi.fn()}
      />,
    );
    expect(container.querySelector(".border-l-security")).not.toBeNull();
    expect(container.querySelector(".bg-danger")).toBeNull();
  });

  it("activates a row on click and on Enter, opening the drawer", () => {
    const { onRowActivate } = renderTable();
    const row = screen.getByTestId("audit-timeline__row-a");
    fireEvent.click(row);
    expect(onRowActivate).toHaveBeenCalledWith(SECURITY);
    fireEvent.keyDown(row, { key: "Enter" });
    expect(onRowActivate).toHaveBeenCalledTimes(2);
  });

  it("does not activate the row when an in-row copy control is used", () => {
    const { onRowActivate } = renderTable();
    fireEvent.click(screen.getByRole("button", { name: /copiar id de actor/i }));
    expect(onRowActivate).not.toHaveBeenCalled();
  });

  it("toggles the sort when the time header is pressed", () => {
    const onToggleSort = vi.fn();
    renderTable({ onToggleSort });
    fireEvent.click(screen.getByRole("button", { name: /hora/i }));
    expect(onToggleSort).toHaveBeenCalledTimes(1);
  });

  it("offers the row pivots via the ⋯ menu only when handlers are provided", () => {
    const onFollowCorrelation = vi.fn();
    renderTable({ onFollowCorrelation });
    // The security row carries an api_key actor + a resource → it has a ⋯ trigger.
    expect(screen.getByTestId("audit-row-actions__trigger-a")).toBeInTheDocument();
  });

  it("renders no ⋯ trigger when no pivot handler is supplied", () => {
    renderTable();
    expect(screen.queryByTestId("audit-row-actions__trigger-a")).toBeNull();
  });

  it("offers no follow-resource pivot for an anonymized resource", () => {
    // Symmetrical to the drawer, and the row menu is the primary path to the pivot rather than the
    // secondary one. With follow-resource the only handler and the axis erased, no pivot is left, so
    // the whole trigger goes — asserted on the trigger rather than on the opened item because a
    // just-opened popup can close before its content mounts.
    renderTable({
      groups: groupsOf([{ ...SECURITY, resourceErased: true }]),
      onFollowResource: vi.fn(),
    });
    expect(screen.queryByTestId("audit-row-actions__trigger-a")).toBeNull();
  });

  it("renders an erased resource as its legal state, never as an id", () => {
    // The pseudonym left on the column is a fresh UUID denoting nobody: showing it as an id — or
    // letting it be copied into a ticket — fabricates a reference. Same decision the actor axis takes.
    renderTable({ groups: groupsOf([{ ...SECURITY, resourceErased: true }]) });
    expect(screen.getByText("anonimizado (GDPR)")).toBeInTheDocument();
    expect(screen.queryByText(/019f0360/)).toBeNull();
    expect(screen.queryByTitle("Copiar id de recurso")).toBeNull();
  });
});
