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

  it("drops an operation this client cannot place from the mapped metadata", async () => {
    // The domain prescribes "unknown, never a fourth kind" for an operation this side cannot place, and
    // the snapshot header already renders silence for a row that carries none. A fourth kind therefore
    // has to reach that same silence.
    //
    // This pins the MAPPING half only. `httpClientReturning` stubs `get` without running the validator it
    // is handed, so nothing here exercises the envelope guard; that the guard no longer REJECTS such a row
    // is pinned by the `isAuditEventDetailResponse` assertions below, and both halves are needed — dropping
    // the value without admitting the row would still lose the whole event.
    const httpClient = httpClientReturning({
      data: { ...DETAIL, metadata: { ...DETAIL.metadata, operation: "RESTORED" } },
    });

    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(detail.metadata.operation).toBeUndefined();
    expect(detail.metadata.changes).toEqual({ name: { old: "BBVA", new: "BBVA España" } });
  });

  it("maps a well-formed operation through to the domain shape", async () => {
    const httpClient = httpClientReturning({
      data: { ...DETAIL, metadata: { ...DETAIL.metadata, operation: "UPDATED" } },
    });
    const detail = await new ApiAuditEventDetailRepository(httpClient).findById(DETAIL.id);

    expect(detail.metadata.operation).toBe("UPDATED");
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
    // A well-formed operation, sibling to changes, is accepted.
    expect(
      isAuditEventDetailResponse({
        data: { ...DETAIL, metadata: { ...DETAIL.metadata, operation: "UPDATED" } },
      }),
    ).toBe(true);
    // A row with no diff still validates a legitimate operation on its own.
    expect(
      isAuditEventDetailResponse({ data: { ...DETAIL, metadata: { operation: "CREATED" } } }),
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
    // NOT drift: `operation` is an enum the API owns, so a release adding a fourth kind reaches this
    // client before the client knows its name. Rejecting the envelope would lose the whole event — diff
    // included — over a value the UI does not need, so an unplaceable operation validates and is dropped
    // downstream instead. Both shapes it can arrive in are admitted.
    expect(
      isAuditEventDetailResponse({
        data: { ...DETAIL, metadata: { ...DETAIL.metadata, operation: "RESTORED" } },
      }),
    ).toBe(true);
    expect(isAuditEventDetailResponse({ data: { ...DETAIL, metadata: { operation: 1 } } })).toBe(
      true,
    );
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
