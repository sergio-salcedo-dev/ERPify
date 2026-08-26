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
    const httpClient = httpClientReturning({ data: DETAIL });
    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(
      `/api/v1/backoffice/audit/events/${DETAIL.id}`,
    );
    expect(detail.action).toBe("BANK_UPDATED");
    expect(detail.metadata.changes).toEqual({ name: { old: "BBVA", new: "BBVA España" } });
  });

  it("reconstructs the exact shape, dropping a stray field on the row and inside a change", async () => {
    const httpClient = httpClientReturning({
      data: {
        ...DETAIL,
        stray: "drop me",
        metadata: { changes: { name: { old: "BBVA", new: "BBVA España", note: "drop me too" } } },
      },
    });
    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(detail).not.toHaveProperty("stray");
    expect(detail.metadata.changes?.name).toEqual({ old: "BBVA", new: "BBVA España" });
    expect(detail.metadata.changes?.name).not.toHaveProperty("note");
  });
});

describe("ApiAuditEventDetailRepository response guard", () => {
  it("accepts a well-formed detail and rejects drift", () => {
    expect(isAuditEventDetailResponse({ data: DETAIL })).toBe(true);

    // A non-change row carries an empty/diff-less metadata object — still valid (the diff is optional).
    expect(
      isAuditEventDetailResponse({ data: { ...DETAIL, level: "activity", metadata: {} } }),
    ).toBe(true);
    // Added/removed sides are real nulls (a CREATE/DELETE snapshot).
    expect(
      isAuditEventDetailResponse({
        data: { ...DETAIL, metadata: { changes: { name: { old: null, new: "BBVA" } } } },
      }),
    ).toBe(true);
    // Scalar diff values: number/boolean are accepted.
    expect(
      isAuditEventDetailResponse({
        data: {
          ...DETAIL,
          metadata: {
            changes: { accountCount: { old: 1, new: 2 }, active: { old: false, new: true } },
          },
        },
      }),
    ).toBe(true);
    // An unknown level/actorType token still passes — the read model never narrows them.
    expect(
      isAuditEventDetailResponse({ data: { ...DETAIL, level: "wat", actorType: "robot" } }),
    ).toBe(true);

    // The bare row — every real response is wrapped in `data` — must be rejected, not just accepted
    // as an accidental synonym: this is the exact shape `GET /audit/events/{id}` never sends and the
    // one this guard used to accept, which left `detail` silently null against the real API.
    expect(isAuditEventDetailResponse(DETAIL)).toBe(false);

    // Drift: metadata missing / not an object.
    const { metadata: _m, ...withoutMetadata } = DETAIL;
    void _m;
    expect(isAuditEventDetailResponse({ data: withoutMetadata })).toBe(false);
    expect(isAuditEventDetailResponse({ data: { ...DETAIL, metadata: "nope" } })).toBe(false);
    // Drift: a change side is an object, not a scalar/null.
    expect(
      isAuditEventDetailResponse({
        data: {
          ...DETAIL,
          metadata: { changes: { name: { old: "BBVA", new: { nested: true } } } },
        },
      }),
    ).toBe(false);
    // Drift: a change is missing a side.
    expect(
      isAuditEventDetailResponse({
        data: { ...DETAIL, metadata: { changes: { name: { old: "BBVA" } } } },
      }),
    ).toBe(false);
    // Drift: slim-field type mismatches.
    expect(isAuditEventDetailResponse({ data: { ...DETAIL, id: 1 } })).toBe(false);
    expect(isAuditEventDetailResponse({ data: { ...DETAIL, actorErased: "false" } })).toBe(false);
    expect(isAuditEventDetailResponse({ data: { ...DETAIL, resourceErased: "false" } })).toBe(
      false,
    );
    expect(isAuditEventDetailResponse({ data: null })).toBe(false);
    // Drift: the envelope key itself is missing or misnamed — the shape most likely to appear if the
    // backend's envelope contract shifts again.
    expect(isAuditEventDetailResponse({})).toBe(false);
    expect(isAuditEventDetailResponse({ result: DETAIL })).toBe(false);
    expect(isAuditEventDetailResponse(null)).toBe(false);
    expect(isAuditEventDetailResponse(undefined)).toBe(false);
  });
});
