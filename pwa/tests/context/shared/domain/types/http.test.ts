import { describe, expect, it } from "vitest";
import { HttpStatus } from "@/context/shared/domain/types/http";

describe("HttpStatus", () => {
  it("pins the codes the codebase compares against", () => {
    expect(HttpStatus.OK).toBe(200);
    expect(HttpStatus.CREATED).toBe(201);
    expect(HttpStatus.NO_CONTENT).toBe(204);
    expect(HttpStatus.BAD_REQUEST).toBe(400);
    expect(HttpStatus.UNAUTHORIZED).toBe(401);
    expect(HttpStatus.FORBIDDEN).toBe(403);
    expect(HttpStatus.NOT_FOUND).toBe(404);
    expect(HttpStatus.CONFLICT).toBe(409);
    expect(HttpStatus.UNPROCESSABLE_ENTITY).toBe(422);
    expect(HttpStatus.INTERNAL_SERVER_ERROR).toBe(500);
    expect(HttpStatus.BAD_GATEWAY).toBe(502);
    expect(HttpStatus.SERVICE_UNAVAILABLE).toBe(503);
  });

  it("derives the matching union type via keyof typeof", () => {
    // Forces TS to error here if the constant gains a value the type misses.
    const allowed: HttpStatus[] = [
      HttpStatus.OK,
      HttpStatus.CREATED,
      HttpStatus.NO_CONTENT,
      HttpStatus.BAD_REQUEST,
      HttpStatus.UNAUTHORIZED,
      HttpStatus.FORBIDDEN,
      HttpStatus.NOT_FOUND,
      HttpStatus.CONFLICT,
      HttpStatus.UNPROCESSABLE_ENTITY,
      HttpStatus.INTERNAL_SERVER_ERROR,
      HttpStatus.BAD_GATEWAY,
      HttpStatus.SERVICE_UNAVAILABLE,
    ];
    expect(allowed).toHaveLength(12);
    expect(new Set(allowed).size).toBe(12);
  });
});
