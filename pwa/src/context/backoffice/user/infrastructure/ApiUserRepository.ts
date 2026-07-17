import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { buildSearchParams } from "@/context/shared/search/infrastructure";
import { WIRE_MAX_LIMIT, type PageEnvelope } from "@/context/shared/search/domain";
import type {
  CrudRepository,
  ResourceSearchCriteria,
  ResourceSearchPage,
} from "@/context/shared/resource/domain/CrudRepository";
import { User, type UserPrimitives } from "../domain/User";
import type { UserInput } from "../domain/UserRepository";
import { UserProblemType } from "../domain/UserProblemType";

interface UserSearchResponse {
  data: UserPrimitives[];
  pagination: PageEnvelope;
}

interface UserSingleResponse {
  data: UserPrimitives;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isStringArray(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => typeof item === "string");
}

function isNumberOrNull(value: unknown): value is number | null {
  return value === null || typeof value === "number";
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

// The read-side row is exactly `{id, email, status, roles, createdAt, updatedAt}` —
// no `password_hash`/`failedAttempts`/`lockedUntil`/`permissions`. The guard proves
// the shape (types + no legacy fields), not enum membership: the backend sends the
// same enum values the PWA declares, so `status`/`roles` are trusted as strings.
function isUserPrimitives(value: unknown): value is UserPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.email === "string" &&
    typeof value.status === "string" &&
    isStringArray(value.roles) &&
    typeof value.createdAt === "string" &&
    typeof value.updatedAt === "string"
  );
}

/**
 * The adapter's trust boundary. Accepts ONLY the cursor-only envelope v2
 * (`hasNext`/`hasPrev`/`count`/`links`) and REJECTS the legacy page-based shape
 * (`currentPage`/`hasMorePages`/`cursor`), so a server still on the old contract
 * surfaces as a typed failure instead of a silent mismap. Shared with the
 * navigation adapter ({@link ApiUserSearchNavigator}).
 */
export function isUserSearchResponse(value: unknown): value is UserSearchResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  if (!data.every(isUserPrimitives) || !isObjectRecord(pagination)) {
    return false;
  }
  const { hasNext, hasPrev, count, links } = pagination;
  return (
    typeof hasNext === "boolean" &&
    typeof hasPrev === "boolean" &&
    isNumberOrNull(count) &&
    isObjectRecord(links) &&
    isStringOrNull(links.next) &&
    isStringOrNull(links.prev)
  );
}

export function isUserSingleResponse(value: unknown): value is UserSingleResponse {
  return isObjectRecord(value) && isUserPrimitives(value.data);
}

/**
 * Maps a validated search response to the generic resource page: items plus the
 * envelope carried through verbatim (the `links` are server-composed and never
 * rebuilt). Shared so the query and navigation adapters map identically.
 */
export function toUserSearchPage(response: UserSearchResponse): ResourceSearchPage<User> {
  return {
    items: response.data.map(User.fromPrimitives),
    hasNext: response.pagination.hasNext,
    hasPrev: response.pagination.hasPrev,
    count: response.pagination.count,
    links: response.pagination.links,
  };
}

// The identity aggregate is invitation-based: there is no create/update/delete
// endpoint to call. Its write use cases (invite / change-status / erase) arrive in
// later stories, so these bind directly to a typed failure. In this console they
// are also unreachable — the action buttons are gated behind `<Can>`.
function notSupported(operation: string): HttpError {
  return new HttpError({
    type: UserProblemType.NOT_SUPPORTED,
    title: "Operation not supported",
    status: HttpStatus.NOT_IMPLEMENTED,
    detail: `Users are invitation-based; ${operation} is not available from this console yet.`,
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  });
}

/**
 * Live read-side adapter for the identity register, bound directly to the generic
 * {@link CrudRepository} port — no bespoke adapter layer, because the port is
 * already generic (unlike Banks, whose adapter remaps a bespoke `{banks}` shape).
 * `search`/`find` are read-only; the write methods reject as unsupported.
 */
@injectable()
export class ApiUserRepository implements CrudRepository<User, UserInput> {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<User>> {
    // First page / query change only: filters + sort + limit, never a cursor.
    // Continuing pages is the navigator following the server-composed `links`
    // verbatim, so navigation has a single authority.
    const params = buildSearchParams(criteria.filters);
    if (criteria.sort) {
      params.append("sort", criteria.sort.field);
      // The API sort enum is uppercase (`ASC`/`DESC`); the PWA enum is lowercase.
      params.append("direction", criteria.sort.direction.toUpperCase());
    }
    // Clamp to the wire ceiling: the UI can never emit `limit > 100`, hard client
    // enforcement complementary to the backend 422.
    params.append("limit", String(Math.min(criteria.limit, WIRE_MAX_LIMIT)));

    const response = await this.httpClient.get(
      `${API_ENDPOINTS.BACKOFFICE.USERS.LIST}?${params.toString()}`,
      isUserSearchResponse,
    );

    return toUserSearchPage(response);
  }

  async find(id: string): Promise<User> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.USERS.DETAILS(id),
      isUserSingleResponse,
    );
    return User.fromPrimitives(response.data);
  }

  create(): Promise<User> {
    return Promise.reject(notSupported("creating a user"));
  }

  update(): Promise<User> {
    return Promise.reject(notSupported("updating a user"));
  }

  delete(): Promise<void> {
    return Promise.reject(notSupported("deleting a user"));
  }
}
