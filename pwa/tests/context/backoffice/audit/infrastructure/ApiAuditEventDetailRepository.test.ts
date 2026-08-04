import { describe, expect, it, vi } from "vitest";
import {
  ApiAuditEventDetailRepository,
  isAuditEventDetailResponse,
} from "@/context/backoffice/audit/infrastructure/ApiAuditEventDetailRepository";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const DETAIL = {
  id: "019f0691-2b5b-731e-9509-3470576159d6",
  occurredOn: "2026-06-27T00:53:24.955168+00:00",
  level: "change",
  action: "BANK_UPDATED",
  actorType: "anonymous",
  actorId: null,
  correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
  resourceType: "Bank",
  resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
  actorErased: false,
  resourceErased: false,
  metadata: { changes: { name: { old: "BBVA", new: "BBVA España" } } },
};

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiAuditEventDetailRepository.findById", () => {
  it("requests the event-detail resource for the id and maps the decoded diff", async () => {
    const httpClient = httpClientReturning(DETAIL);
    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(
      `/api/v1/backoffice/audit/events/${DETAIL.id}`,
    );
    expect(detail.action).toBe("BANK_UPDATED");
    expect(detail.metadata.changes).toEqual({ name: { old: "BBVA", new: "BBVA España" } });
  });

  it("reconstructs the exact shape, dropping a stray field on the row and inside a change", async () => {
    const httpClient = httpClientReturning({
      ...DETAIL,
      stray: "drop me",
      metadata: { changes: { name: { old: "BBVA", new: "BBVA España", note: "drop me too" } } },
    });
    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(detail).not.toHaveProperty("stray");
    expect(detail.metadata.changes?.name).toEqual({ old: "BBVA", new: "BBVA España" });
    expect(detail.metadata.changes?.name).not.toHaveProperty("note");
  });
});

describe("ApiAuditEventDetailRepository response guard", () => {
  it("accepts a well-formed detail and rejects drift", () => {
    expect(isAuditEventDetailResponse(DETAIL)).toBe(true);

    // A non-change row carries an empty/diff-less metadata object — still valid (the diff is optional).
    expect(isAuditEventDetailResponse({ ...DETAIL, level: "activity", metadata: {} })).toBe(true);
    // Added/removed sides are real nulls (a CREATE/DELETE snapshot).
    expect(
      isAuditEventDetailResponse({
        ...DETAIL,
        metadata: { changes: { name: { old: null, new: "BBVA" } } },
      }),
    ).toBe(true);
    // Scalar diff values: number/boolean are accepted.
    expect(
      isAuditEventDetailResponse({
        ...DETAIL,
        metadata: {
          changes: { accountCount: { old: 1, new: 2 }, active: { old: false, new: true } },
        },
      }),
    ).toBe(true);
    // An unknown level/actorType token still passes — the read model never narrows them.
    expect(isAuditEventDetailResponse({ ...DETAIL, level: "wat", actorType: "robot" })).toBe(true);

    // Drift: metadata missing / not an object.
    const { metadata: _m, ...withoutMetadata } = DETAIL;
    void _m;
    expect(isAuditEventDetailResponse(withoutMetadata)).toBe(false);
    expect(isAuditEventDetailResponse({ ...DETAIL, metadata: "nope" })).toBe(false);
    // Drift: a change side is an object, not a scalar/null.
    expect(
      isAuditEventDetailResponse({
        ...DETAIL,
        metadata: { changes: { name: { old: "BBVA", new: { nested: true } } } },
      }),
    ).toBe(false);
    // Drift: a change is missing a side.
    expect(
      isAuditEventDetailResponse({ ...DETAIL, metadata: { changes: { name: { old: "BBVA" } } } }),
    ).toBe(false);
    // Drift: slim-field type mismatches.
    expect(isAuditEventDetailResponse({ ...DETAIL, id: 1 })).toBe(false);
    expect(isAuditEventDetailResponse({ ...DETAIL, actorErased: "false" })).toBe(false);
    expect(isAuditEventDetailResponse({ ...DETAIL, resourceErased: "false" })).toBe(false);
    expect(isAuditEventDetailResponse(null)).toBe(false);
    expect(isAuditEventDetailResponse(undefined)).toBe(false);
  });
});
