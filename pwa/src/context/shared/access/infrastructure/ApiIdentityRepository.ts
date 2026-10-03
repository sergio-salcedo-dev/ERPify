import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { SharedProblemType } from "@/context/shared/error/domain/SharedProblemType";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { UserStatus } from "../domain/UserStatus";
import { ALL_PERMISSIONS, type HeldPermission, type Permission } from "../domain/Permission";
import type { Identity } from "../domain/Identity";
import { IdentityServiceUnavailableError } from "../domain/IdentityServiceUnavailableError";
import type { ChangePasswordCommand, IdentityRepository } from "../domain/IdentityRepository";

interface MeResponse {
  id: string;
  email: string;
  roles: string[];
  permissions: string[];
}

interface MeEnvelope {
  data: MeResponse;
}

function isMeResource(value: unknown): value is MeResponse {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Partial<MeResponse>;
  return (
    typeof candidate.id === "string" &&
    typeof candidate.email === "string" &&
    Array.isArray(candidate.roles) &&
    candidate.roles.every((role) => typeof role === "string") &&
    Array.isArray(candidate.permissions) &&
    candidate.permissions.every((permission) => typeof permission === "string")
  );
}

const isMeEnvelope: ResponseGuard<MeEnvelope> = (body): body is MeEnvelope => {
  if (typeof body !== "object" || body === null) return false;
  return isMeResource((body as Partial<MeEnvelope>).data);
};

const KNOWN_PERMISSIONS = new Set<string>(ALL_PERMISSIONS);

/**
 * The API publishes the whole installation's vocabulary (bank, bankAccount, audit…), of which this console
 * declares only the slice it gates on. Anything it cannot name is dropped rather than widening the session
 * type: a permission nothing gates on cannot change what renders, so keeping it would buy nothing and cost
 * the type its meaning.
 */
function knownPermissionsOf(permissions: string[]): HeldPermission[] {
  return permissions.filter((permission): permission is Permission =>
    KNOWN_PERMISSIONS.has(permission),
  );
}

function isServiceUnavailable(error: unknown): boolean {
  return (
    error instanceof HttpError &&
    error.problem.status === HttpStatus.SERVICE_UNAVAILABLE &&
    error.problem.type === SharedProblemType.SERVICE_UNAVAILABLE
  );
}

/**
 * HTTP adapter over the signed-in identity: reads it from the gated `/me` endpoint and
 * changes its credential through `POST /me/password`.
 *
 * `me()`:
 *
 *  - 200 → the live identity. A 200 is only returned for an admitted session, so
 *    the user is ACTIVE by construction. Roles are the backend names, stored
 *    verbatim; permissions are the set the API derives from them, narrowed to the
 *    ones this client declares (anything else — the wildcard included — is dropped).
 *  - 401 (`session-expired`) → no live session → null.
 *  - 503 `service-unavailable` → a dependency the server needs to decide the request is
 *    unreachable (on this route, typically the admission gate failing to reach the session
 *    store; the type is the API's generic 503, so it is not proof of which one), so the server
 *    cannot tell whether a session is live → {@link IdentityServiceUnavailableError}. Both the
 *    status and the `type` must match: a 503 without that problem body (a proxy's own page) did
 *    not come from the API and proves nothing about it.
 *
 * Any other failure (network, malformed body, another status) propagates as the transport's
 * error, so the caller can still tell "no session" from "could not reach the server".
 *
 * The set is a rendering convenience only: every route enforces its own authorization server-side, so a
 * tampered session gains nothing beyond seeing controls that then fail.
 */
@injectable()
export class ApiIdentityRepository implements IdentityRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async me(): Promise<Identity | null> {
    try {
      const { data } = await this.httpClient.get(API_ENDPOINTS.IDENTITY.ME, isMeEnvelope);
      return {
        id: data.id,
        email: data.email,
        status: UserStatus.ACTIVE,
        roles: [...data.roles],
        permissions: knownPermissionsOf(data.permissions),
      };
    } catch (error) {
      if (error instanceof HttpError && error.problem.status === HttpStatus.UNAUTHORIZED) {
        return null;
      }
      if (isServiceUnavailable(error)) {
        throw new IdentityServiceUnavailableError();
      }
      throw error;
    }
  }

  /**
   * The 204 carries no body, so no response guard applies. Failures propagate as the
   * transport's `HttpError`: the caller distinguishes them by the problem `type`, which
   * is the contract — a wrong current password is a 403, not a 401, so the transport
   * never mistakes it for an expired session.
   */
  async changePassword(command: ChangePasswordCommand): Promise<void> {
    await this.httpClient.post<ChangePasswordCommand, void>(
      API_ENDPOINTS.IDENTITY.CHANGE_PASSWORD,
      command,
    );
  }
}
