import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { UserStatus } from "../domain/UserStatus";
import type { Identity } from "../domain/Identity";
import type { IdentityRepository } from "../domain/IdentityRepository";

interface MeResponse {
  id: string;
  email: string;
  roles: string[];
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
    candidate.roles.every((role) => typeof role === "string")
  );
}

const isMeEnvelope: ResponseGuard<MeEnvelope> = (body): body is MeEnvelope => {
  if (typeof body !== "object" || body === null) return false;
  return isMeResource((body as Partial<MeEnvelope>).data);
};

/**
 * Resolves the signed-in identity from the gated `/me` endpoint.
 *
 *  - 200 → the live identity. A 200 is only returned for an admitted session, so
 *    the user is ACTIVE by construction. `/me` carries no permission set —
 *    frontend permission gating is a later story — so the session holds none
 *    (never a fabricated wildcard). Roles are the backend names, stored verbatim.
 *  - 401 (`session-expired`) → no live session → null.
 *
 * A non-401 failure (network / malformed body) propagates so the caller can
 * distinguish "no session" from "could not reach the server".
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
        permissions: [],
      };
    } catch (error) {
      if (error instanceof HttpError && error.problem.status === HttpStatus.UNAUTHORIZED) {
        return null;
      }
      throw error;
    }
  }
}
