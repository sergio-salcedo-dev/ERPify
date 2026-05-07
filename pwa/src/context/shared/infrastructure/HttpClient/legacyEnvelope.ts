import {
  isProblemDetails,
  type ProblemDetails,
  type ProblemViolation,
} from "../../domain/ProblemDetails";

interface LegacyEnvelope {
  errors: LegacyError[];
  meta?: { requestId?: unknown };
}

interface LegacyError {
  source?: { parameter?: unknown };
  title?: unknown;
}

export interface ProblemFallback {
  type: string;
  title: string;
}

export function toProblemDetails(
  body: unknown,
  status: number,
  fallback: ProblemFallback,
): ProblemDetails {
  if (isProblemDetails(body)) {
    return body;
  }

  if (isLegacyEnvelope(body)) {
    const errors = body.errors;
    const violations: ProblemViolation[] = errors.map((entry) => ({
      field: typeof entry?.source?.parameter === "string" ? entry.source.parameter : "",
      message: typeof entry?.title === "string" ? entry.title : "",
    }));
    const firstTitle =
      errors.length > 0 && typeof errors[0]?.title === "string" ? errors[0].title : fallback.title;
    const requestId =
      typeof body.meta?.requestId === "string" ? body.meta.requestId : crypto.randomUUID();
    const maybeInstance = (body as unknown as { instance?: unknown }).instance;
    const instance = typeof maybeInstance === "string" ? maybeInstance : crypto.randomUUID();

    return {
      type: fallback.type,
      title: firstTitle,
      status,
      instance,
      "correlation-id": requestId,
      ...(violations.length > 0 ? { violations } : {}),
    };
  }

  return {
    type: fallback.type,
    title: fallback.title,
    status,
    instance: crypto.randomUUID(),
    "correlation-id": crypto.randomUUID(),
  };
}

function isLegacyEnvelope(value: unknown): value is LegacyEnvelope {
  if (typeof value !== "object" || value === null) return false;
  const v = value as { errors?: unknown };
  return Array.isArray(v.errors);
}
