import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import type { SessionSummary } from "../domain/SessionSummary";
import type { SessionsRepository } from "../domain/SessionsRepository";

interface SessionsEnvelope {
  data: SessionSummary[];
}

function isSessionSummary(value: unknown): value is SessionSummary {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Partial<SessionSummary>;
  return (
    typeof candidate.id === "string" &&
    typeof candidate.device === "string" &&
    typeof candidate.createdAt === "string" &&
    typeof candidate.current === "boolean"
  );
}

const isSessionsEnvelope: ResponseGuard<SessionsEnvelope> = (body): body is SessionsEnvelope => {
  if (typeof body !== "object" || body === null) return false;
  const candidate = body as Partial<SessionsEnvelope>;
  return Array.isArray(candidate.data) && candidate.data.every(isSessionSummary);
};

/**
 * HTTP adapter over the signed-in user's own session registry:
 *  - `GET /sessions` → `{ data: [...] }` envelope of active sessions.
 *  - `POST /sessions/revoke-others` → 204 (revokes every session but the current).
 *  - `POST /sessions/revoke-current` → 204 (revokes this session; the server drops the cookie).
 */
@injectable()
export class ApiSessionsRepository implements SessionsRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async list(): Promise<SessionSummary[]> {
    const { data } = await this.httpClient.get(API_ENDPOINTS.IDENTITY.SESSIONS, isSessionsEnvelope);
    return data;
  }

  async revokeOthers(): Promise<void> {
    await this.httpClient.post<undefined, void>(
      API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_OTHERS,
      undefined,
    );
  }

  async revokeCurrent(budgetMs?: number): Promise<void> {
    await this.httpClient.post<undefined, void>(
      API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_CURRENT,
      undefined,
      undefined,
      budgetMs === undefined ? undefined : { timeoutMs: budgetMs },
    );
  }
}
