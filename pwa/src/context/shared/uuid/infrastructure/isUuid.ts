import { validate } from "uuid";

/**
 * True when `value` is a syntactically valid UUID (any version). Delegates to the `uuid` library's
 * `validate` so validation stays in lock-step with generation and the dependency stays confined to
 * this capability (never `import … from "uuid"` in a component). Use it before a route param / API
 * id flows into a URL, Mercure topic IRI, or request — client-side defense in depth, never a substitute
 * for the API's own `#[Assert\Uuid]`.
 */
export function isUuid(value: string): boolean {
  return validate(value);
}
