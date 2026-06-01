import { describe, expect, it } from "vitest";
import { initials } from "@/components/erpify/initials";

describe("initials", () => {
  it("takes the first letter of the first two words, uppercased", () => {
    expect(initials("Santander Bank")).toBe("SB");
  });

  it("uses the first two letters when there is a single word", () => {
    expect(initials("BBVA")).toBe("BB");
  });

  it("handles a single-letter name", () => {
    expect(initials("X")).toBe("X");
  });

  it("ignores surrounding and inner whitespace", () => {
    expect(initials("  caixa   bank  ")).toBe("CB");
  });

  it("returns an empty string for an empty or whitespace-only name", () => {
    expect(initials("")).toBe("");
    expect(initials("   ")).toBe("");
  });
});
