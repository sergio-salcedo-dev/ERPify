import {
  isProblemDetails,
  type ProblemDetails,
} from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";

/**
 * The browser-side API base, same-origin by default — the same rule `FetchHttpClient`,
 * `imageReadUrl` and the Mercure subscriber apply. The two Documents transfers that cannot go
 * through `HttpClient` (the raw-body PUT, which needs upload progress, and the byte read) resolve
 * their URL here, so they reach the same origin the JSON calls do.
 */
export function documentsUrl(path: string): string {
  const base = (process.env.NEXT_PUBLIC_API_BASE_URL?.trim() ?? "").replace(/\/$/, "");
  return `${base}${path}`;
}

/**
 * A non-2xx answer as the transport's `HttpError`: the server's Problem Details when the body is
 * one, otherwise a minimal one carrying the status — never a synthesised `type` of the client's.
 */
export function problemFrom(status: number, body: string, correlationId: string | null): HttpError {
  let parsed: unknown = null;
  try {
    parsed = JSON.parse(body);
  } catch {
    parsed = null;
  }
  const problem: ProblemDetails = isProblemDetails(parsed)
    ? parsed
    : {
        type: "about:blank",
        title: `HTTP ${status}`,
        status,
        instance: uuidV7(),
        "correlation-id": correlationId ?? uuidV7(),
      };
  return new HttpError(problem);
}

/** A transfer that never got an answer (offline, DNS, a connection cut mid-body). */
export function unreachable(): HttpError {
  return new HttpError({
    type: NETWORK_ERROR,
    title: "Could not reach the server",
    status: 0,
    detail: "Check your connection and try again.",
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  });
}
