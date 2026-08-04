import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AuditEntryDrawer } from "@/context/backoffice/audit/infrastructure/ui/AuditEntryDrawer";
import type { AuditEntry } from "@/context/backoffice/audit/domain/AuditEntry";

const ENTRY: AuditEntry = {
  id: "019f0691-2b5b-731e-9509-3470576159d6",
  occurredOn: "2026-06-12T12:04:31.118402+00:00",
  level: "security",
  action: "ACCESS_DENIED",
  actorType: "api_key",
  actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
  correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
  resourceType: "BankAccount",
  resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
  actorErased: false,
  resourceErased: false,
};

describe("AuditEntryDrawer", () => {
  it("renders the humanized action title with the raw token preserved", () => {
    render(<AuditEntryDrawer entry={ENTRY} open onClose={vi.fn()} />);
    // Appears as the drawer title and as the «Acción» field value.
    expect(screen.getAllByText("Acceso denegado").length).toBeGreaterThan(0);
    expect(screen.getAllByText("ACCESS_DENIED").length).toBeGreaterThan(0);
  });

  it("keeps the PII detail sections dormant when no detail is supplied (4.2a deferred)", () => {
    render(<AuditEntryDrawer entry={ENTRY} open onClose={vi.fn()} />);
    // IP, user agent and metadata each show the dormant note.
    expect(screen.getAllByTestId("audit-entry-drawer__dormant")).toHaveLength(3);
    expect(screen.queryByText("[REDACTED]")).toBeNull();
  });

  it("renders the field-by-field diff for a change entry from a supplied detail", () => {
    const changeEntry: AuditEntry = {
      ...ENTRY,
      level: "change",
      action: "BANK_UPDATED",
      resourceType: "Bank",
    };
    render(
      <AuditEntryDrawer
        entry={changeEntry}
        open
        onClose={vi.fn()}
        detail={{
          ...changeEntry,
          metadata: { changes: { name: { old: "BBVA", new: "BBVA S.A." } } },
        }}
      />,
    );
    expect(screen.getByTestId("audit-entry-drawer__diff")).toBeInTheDocument();
    expect(screen.getByText("BBVA")).toBeInTheDocument();
    expect(screen.getByText("BBVA S.A.")).toBeInTheDocument();
  });

  it("exposes query pivots that fire their callbacks (the keyboard-complete path)", () => {
    const onFollowActor = vi.fn();
    const onFollowCorrelation = vi.fn();
    const onFollowResource = vi.fn();
    render(
      <AuditEntryDrawer
        entry={ENTRY}
        open
        onClose={vi.fn()}
        onFollowActor={onFollowActor}
        onFollowCorrelation={onFollowCorrelation}
        onFollowResource={onFollowResource}
      />,
    );
    fireEvent.click(screen.getByTestId("audit-entry-drawer__follow-actor"));
    fireEvent.click(screen.getByTestId("audit-entry-drawer__follow-correlation"));
    fireEvent.click(screen.getByTestId("audit-entry-drawer__follow-resource"));
    expect(onFollowActor).toHaveBeenCalledWith(ENTRY);
    expect(onFollowCorrelation).toHaveBeenCalledWith(ENTRY);
    expect(onFollowResource).toHaveBeenCalledWith(ENTRY);
  });

  it("offers no follow-actor pivot for an anonymized actor (random UUID is not identity)", () => {
    render(
      <AuditEntryDrawer
        entry={{ ...ENTRY, actorErased: true }}
        open
        onClose={vi.fn()}
        onFollowActor={vi.fn()}
      />,
    );
    expect(screen.queryByTestId("audit-entry-drawer__follow-actor")).toBeNull();
  });

  it("offers no follow-resource pivot for an anonymized resource (random UUID is not identity)", () => {
    // The compliance row of an identity erasure is exactly this shape: it names the erased subject as its
    // resource and the anonymiser rewrites that id to a pseudonym in the same transaction. Offering the
    // pivot would filter the trail by an identity that denotes nobody, presented as a live reference.
    render(
      <AuditEntryDrawer
        entry={{ ...ENTRY, resourceErased: true }}
        open
        onClose={vi.fn()}
        onFollowResource={vi.fn()}
      />,
    );
    expect(screen.queryByTestId("audit-entry-drawer__follow-resource")).toBeNull();
  });

  it("copies the entry id", () => {
    render(<AuditEntryDrawer entry={ENTRY} open onClose={vi.fn()} />);
    expect(screen.getByTestId("audit-entry-drawer__copy-id")).toBeInTheDocument();
  });
});
