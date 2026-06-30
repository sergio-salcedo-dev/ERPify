import { describe, expect, it } from "vitest";
import { parseBankAccountRealtimeEvent } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";

const ID = "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c";
const BANK_ID = "11111111-1111-7111-8111-111111111111";

describe("parseBankAccountRealtimeEvent", () => {
  it("parses each lifecycle type into a minimal { kind, id, bankId } event", () => {
    for (const [type, kind] of [
      ["bank_account.created", "created"],
      ["bank_account.updated", "updated"],
      ["bank_account.deleted", "deleted"],
    ] as const) {
      expect(parseBankAccountRealtimeEvent({ type, id: ID, bankId: BANK_ID })).toEqual({
        kind,
        id: ID,
        bankId: BANK_ID,
      });
    }
  });

  it("never reads account PII off the payload — only the ids survive", () => {
    const event = parseBankAccountRealtimeEvent({
      type: "bank_account.created",
      id: ID,
      bankId: BANK_ID,
      iban: "ES9121000418450200051332",
      holderName: "Acme Corp",
    });
    expect(event).toEqual({ kind: "created", id: ID, bankId: BANK_ID });
  });

  it("returns null for an unknown event type", () => {
    expect(
      parseBankAccountRealtimeEvent({ type: "bank_account.archived", id: ID, bankId: BANK_ID }),
    ).toBeNull();
  });

  it("returns null when id or bankId is missing or non-string", () => {
    expect(
      parseBankAccountRealtimeEvent({ type: "bank_account.created", bankId: BANK_ID }),
    ).toBeNull();
    expect(parseBankAccountRealtimeEvent({ type: "bank_account.created", id: ID })).toBeNull();
    expect(
      parseBankAccountRealtimeEvent({ type: "bank_account.created", id: 42, bankId: BANK_ID }),
    ).toBeNull();
  });

  it("returns null for non-object / missing-type payloads", () => {
    expect(parseBankAccountRealtimeEvent("nope")).toBeNull();
    expect(parseBankAccountRealtimeEvent(null)).toBeNull();
    expect(parseBankAccountRealtimeEvent(undefined)).toBeNull();
    expect(parseBankAccountRealtimeEvent({ id: ID, bankId: BANK_ID })).toBeNull();
  });
});
