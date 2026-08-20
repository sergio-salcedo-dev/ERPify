import { describe, it, expect } from "vitest";
import { metadata } from "@/app/backoffice/banks/new/page";

describe("New bank page metadata", () => {
  it("declares the new bank page title", () => {
    expect(metadata.title).toBe("New bank");
  });
});
