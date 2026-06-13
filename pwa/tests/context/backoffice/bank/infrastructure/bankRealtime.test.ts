import { describe, expect, it } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { parseBankRealtimeEvent } from "@/context/backoffice/bank/infrastructure/bankRealtime";

const primitives = {
  id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
  accountCount: 0,
};

describe("parseBankRealtimeEvent", () => {
  it("parses a created event into a Bank", () => {
    const event = parseBankRealtimeEvent({ type: "bank.created", bank: primitives });
    expect(event?.kind).toBe("created");
    if (event?.kind === "created") {
      expect(event.bank).toBeInstanceOf(Bank);
      expect(event.bank.id).toBe(primitives.id);
      expect(event.bank.name).toBe("Acme Savings");
    }
  });

  it("parses an updated event into a Bank", () => {
    const event = parseBankRealtimeEvent({ type: "bank.updated", bank: primitives });
    expect(event?.kind).toBe("updated");
    if (event?.kind === "updated") {
      expect(event.bank.shortName).toBe("ACME");
    }
  });

  it("parses a deleted event into an id", () => {
    expect(parseBankRealtimeEvent({ type: "bank.deleted", id: primitives.id })).toEqual({
      kind: "deleted",
      id: primitives.id,
    });
  });

  it("returns null for an unknown event type", () => {
    expect(parseBankRealtimeEvent({ type: "bank.archived", bank: primitives })).toBeNull();
  });

  it("returns null when the bank payload is malformed", () => {
    expect(
      parseBankRealtimeEvent({ type: "bank.created", bank: { id: primitives.id } }),
    ).toBeNull();
  });

  it("returns null when accountCount is missing from the bank payload", () => {
    const { accountCount: _, ...withoutCount } = primitives;
    expect(parseBankRealtimeEvent({ type: "bank.created", bank: withoutCount })).toBeNull();
    expect(parseBankRealtimeEvent({ type: "bank.updated", bank: withoutCount })).toBeNull();
  });

  it("maps accountCount correctly from the event payload", () => {
    const event = parseBankRealtimeEvent({
      type: "bank.updated",
      bank: { ...primitives, accountCount: 7 },
    });
    expect(event?.kind).toBe("updated");
    if (event?.kind === "updated") {
      expect(event.bank.accountCount).toBe(7);
    }
  });

  it("returns null when the deleted id is missing", () => {
    expect(parseBankRealtimeEvent({ type: "bank.deleted" })).toBeNull();
  });

  it("returns null for non-object payloads", () => {
    expect(parseBankRealtimeEvent("nope")).toBeNull();
    expect(parseBankRealtimeEvent(null)).toBeNull();
    expect(parseBankRealtimeEvent(undefined)).toBeNull();
  });
});
