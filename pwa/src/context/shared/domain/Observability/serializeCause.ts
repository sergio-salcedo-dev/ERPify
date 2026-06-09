import { scrubDeep } from "./redaction";

const MAX_MESSAGE_CHARS = 1024;
const MAX_STACK_CHARS = 4096;
const MAX_CAUSE_CHAIN = 5;

/**
 * Plain, transmittable shape of an arbitrary `cause`. Either an Error-derived
 * record (name / message / stack / nested cause) or a scrubbed `value` for
 * non-Error causes.
 */
export interface SerializedCause {
  name?: string;
  message?: string;
  stack?: string;
  cause?: SerializedCause;
  value?: unknown;
}

function truncate(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max)}… [truncated]` : text;
}

/**
 * Normalizes an unknown `cause` into a plain, size-bounded, PII-scrubbed object
 * safe to attach to an external sink event. Walks the Error `cause` chain
 * (bounded), truncates message/stack, and recursively strips
 * {@link scrubDeep | denylisted keys} from any structured non-Error value.
 *
 * Vendor-neutral on purpose: the Sentry adapter and SDK `beforeSend` consume it
 * today, the future Datadog adapter reuses it unchanged. Error message/stack
 * text is forwarded as-is (only key-named fields are scrubbed) — matching the
 * API scrubber, where free-text exception messages are out of redaction scope.
 */
export function serializeCause(cause: unknown, chainDepth = 0): SerializedCause | undefined {
  if (cause === undefined || cause === null) {
    return undefined;
  }

  if (cause instanceof Error) {
    const serialized: SerializedCause = {
      name: cause.name,
      message: truncate(cause.message, MAX_MESSAGE_CHARS),
    };
    if (typeof cause.stack === "string") {
      serialized.stack = truncate(cause.stack, MAX_STACK_CHARS);
    }
    if (cause.cause !== undefined && cause.cause !== null && chainDepth < MAX_CAUSE_CHAIN) {
      serialized.cause = serializeCause(cause.cause, chainDepth + 1);
    }
    return serialized;
  }

  if (typeof cause === "object") {
    return { value: scrubDeep(cause) };
  }

  return { value: cause };
}
