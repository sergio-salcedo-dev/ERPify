import { describe, expect, it } from "vitest";
import { serializeCause } from "@/context/shared/domain/Observability/serializeCause";

describe("serializeCause", () => {
  it("returns undefined for undefined / null", () => {
    expect(serializeCause(undefined)).toBeUndefined();
    expect(serializeCause(null)).toBeUndefined();
  });

  it("normalizes an Error to name + message + stack", () => {
    const error = new Error("boom");
    const result = serializeCause(error);
    expect(result?.name).toBe("Error");
    expect(result?.message).toBe("boom");
    expect(typeof result?.stack).toBe("string");
  });

  it("walks the Error cause chain, bounded", () => {
    const root = new Error("root");
    const wrapped = new Error("wrapped", { cause: root });
    const result = serializeCause(wrapped);
    expect(result?.cause?.message).toBe("root");
  });

  it("truncates an oversized message", () => {
    const result = serializeCause(new Error("a".repeat(5000)));
    expect(result?.message?.endsWith("… [truncated]")).toBe(true);
    expect(result?.message?.length).toBeLessThan(5000);
  });

  it("scrubs denylisted keys from a non-Error object cause", () => {
    const result = serializeCause({ id: 1, password: "p", nested: { token: "t", ok: true } });
    expect(result?.value).toEqual({ id: 1, nested: { ok: true } });
  });

  it("wraps a primitive cause as value", () => {
    expect(serializeCause("plain")).toEqual({ value: "plain" });
  });
});
