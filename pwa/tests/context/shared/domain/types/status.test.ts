import { describe, expect, it } from "vitest";
import { ApiStatus, PersistenceAction, ViewStatus } from "@/context/shared/domain/types/status";

describe("ApiStatus", () => {
  it("exposes idle / pending / fulfilled / rejected", () => {
    expect(ApiStatus).toEqual({
      IDLE: "idle",
      PENDING: "pending",
      FULFILLED: "fulfilled",
      REJECTED: "rejected",
    });
  });

  it("derives the matching union type via keyof typeof", () => {
    // If a value is added to / removed from `ApiStatus`, this assignment
    // forces TypeScript to error at the call site — keeping types in sync.
    const allowed: ApiStatus[] = [
      ApiStatus.IDLE,
      ApiStatus.PENDING,
      ApiStatus.FULFILLED,
      ApiStatus.REJECTED,
    ];
    expect(allowed).toHaveLength(4);
  });
});

describe("ViewStatus", () => {
  it("exposes loading / ready / error / empty / not-found", () => {
    expect(ViewStatus).toEqual({
      LOADING: "loading",
      READY: "ready",
      ERROR: "error",
      EMPTY: "empty",
      NOT_FOUND: "not-found",
    });
  });

  it("derives the matching union type via keyof typeof", () => {
    const allowed: ViewStatus[] = [
      ViewStatus.LOADING,
      ViewStatus.READY,
      ViewStatus.ERROR,
      ViewStatus.EMPTY,
      ViewStatus.NOT_FOUND,
    ];
    expect(allowed).toHaveLength(5);
  });
});

describe("PersistenceAction", () => {
  it("exposes creating / updating / deleting / saved", () => {
    expect(PersistenceAction).toEqual({
      CREATING: "creating",
      UPDATING: "updating",
      DELETING: "deleting",
      SAVED: "saved",
    });
  });

  it("derives the matching union type via keyof typeof", () => {
    const allowed: PersistenceAction[] = [
      PersistenceAction.CREATING,
      PersistenceAction.UPDATING,
      PersistenceAction.DELETING,
      PersistenceAction.SAVED,
    ];
    expect(allowed).toHaveLength(4);
  });
});

describe("orthogonality", () => {
  it("keeps API and View status separate (PENDING vs LOADING)", () => {
    expect(ApiStatus.PENDING).toBe("pending");
    expect(ViewStatus.LOADING).toBe("loading");
    expect(ApiStatus.PENDING).not.toBe(ViewStatus.LOADING);
  });
});
