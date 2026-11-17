import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { ImageProblemType } from "@/context/shared/images/domain/ImageProblemType";
import { MAX_IMAGE_BYTES } from "@/context/shared/images/domain/imageLimits";

/**
 * The PWA mirrors two facts of the image write routes and nothing tied either to the API: the four
 * Problem Details `type` values it maps to copy, and the byte cap its pre-upload check applies. A
 * mirror that drifts does not fail a build — a renamed `type` falls to the generic fallback and a
 * raised cap refuses files the server would take — so this reads the API's own declarations.
 *
 * It reads `api/src/Shared/Images/Domain/Exception/ImageProblemType.php` (the `public const string
 * NAME = 'value';` lines) and `erpify.images.max_input_bytes` in `api/config/services.yaml`, and
 * asserts both directions for the types: every API value is a PWA value and every PWA value is an
 * API value, so neither a new API type nor a stale PWA one goes unnoticed.
 *
 * Costs of living in the PWA suite, stated rather than hidden — the same ones
 * `client-minted-problem-types.test.ts` accepts: an API developer who renames a type sees this in
 * the PWA's CI job and not in `make php.quality`, and this file makes the PWA suite depend on a
 * sibling tree, so a standalone `pwa/` checkout fails here rather than skipping.
 *
 * Blind spots: it reads constants by text, so a type built dynamically or declared outside that
 * class is invisible; it proves the cap equals the configured default, never that the deployment
 * or the Caddy edge matcher carries the same value (an env override of the parameter is not read);
 * and it says nothing about the allowlist of media types, which the API holds in its
 * `ImageProcessor` rather than in a declaration this file can parse. It asserts its own universe
 * (the file exists and yields four types and a cap) so a moved API file reports RED, not a vacuous
 * pass.
 */
const PWA_ROOT = path.resolve(__dirname, "..");
const REPO_ROOT = path.resolve(PWA_ROOT, "..");
const API_TYPES = path.join(
  REPO_ROOT,
  "api/src/Shared/Images/Domain/Exception/ImageProblemType.php",
);
const API_SERVICES = path.join(REPO_ROOT, "api/config/services.yaml");

const PHP_CONSTANT = /public const string (\w+) = '([^']+)';/g;
const MAX_INPUT_BYTES = /^\s*erpify\.images\.max_input_bytes:\s*(\d+)\b/m;

function read(file: string): string {
  if (!existsSync(file)) {
    throw new Error(
      `${path.relative(REPO_ROOT, file)} is missing. This gate reads the API's own declaration of ` +
        `what the PWA mirrors — update the path rather than deleting the test.`,
    );
  }
  return readFileSync(file, "utf8");
}

function apiTypes(): Map<string, string> {
  return new Map([...read(API_TYPES).matchAll(PHP_CONSTANT)].map((m) => [m[1], m[2]]));
}

describe("the PWA mirrors of the image write contract", () => {
  it("read a non-empty universe from the API, so every row below is about something", () => {
    expect(apiTypes().size).toBe(4);
    expect(read(API_SERVICES)).toMatch(MAX_INPUT_BYTES);
  });

  it("map exactly the Problem Details types the API mints, constant by constant", () => {
    const fromApi = apiTypes();
    const fromPwa = new Map(Object.entries(ImageProblemType));

    expect(Object.fromEntries(fromPwa)).toEqual(Object.fromEntries(fromApi));
  });

  it("apply the byte cap the API is configured with", () => {
    const match = MAX_INPUT_BYTES.exec(read(API_SERVICES));

    expect(match).not.toBeNull();
    expect(MAX_IMAGE_BYTES).toBe(Number(match?.[1]));
  });
});
