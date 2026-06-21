import { describe, expect, it } from "vitest";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";

// Mirrors the API CorrelationIdListener's strict v7 pattern (version nibble `7`, RFC 4122 variant).
const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe("uuidV7", () => {
  it("returns a canonical lowercase UUID v7", () => {
    expect(uuidV7()).toMatch(UUID_V7);
  });

  it("returns a distinct value on each call", () => {
    expect(uuidV7()).not.toBe(uuidV7());
  });
});
