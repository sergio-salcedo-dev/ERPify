import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
  BANK_ACCOUNT_EVIDENCE_KINDS,
  EVIDENCE_RETENTION,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { MAX_DOCUMENT_BYTES } from "@/context/backoffice/document/domain/checkDocumentFile";

/**
 * The evidence surface mirrors three facts the API owns, and nothing tied any of them to it: the
 * byte cap of a Documents original, the kinds of bank-account evidence, and the retention terms
 * each kind is filed under. Each one drifts without failing a build — a raised cap refuses files
 * the server would take, a renamed kind is refused by the attach, and a retention term that
 * disagrees makes the attach refuse every file it was asked to link (422
 * `bank-account-evidence-retention-mismatch`) after its bytes were already uploaded — so this reads
 * the API's own declarations.
 *
 * It reads `UploadLimits::MAX_ORIGINAL_BYTES` (`public const int NAME = value;`, digit separators
 * allowed) and the `BankAccountEvidenceKind` enum: its `case Name = 'value';` lines and the
 * `self::Name => 'value'` arms of its `retentionReason()` and `legalBasis()` matches. The kinds are
 * compared in both directions, so neither a new API kind nor a stale PWA one goes unnoticed.
 *
 * Costs, the same `image-contract-parity.test.ts` accepts: an API developer sees this in the PWA's
 * CI job, not in `make php.quality`, and a standalone `pwa/` checkout fails here rather than
 * skipping. Blind spots: it reads by text, so a value built at runtime, a match arm spelled other
 * than `self::Name => 'literal'` or a cap overridden by configuration is invisible; it says nothing
 * about the Caddy body bound, which `UploadRoutePathAgreementGateTest` owns on the API side. It
 * asserts its own universe (both files exist and yield a cap, at least one kind and an arm per
 * kind) so a moved file reports RED, not a vacuous pass.
 */
const REPO_ROOT = path.resolve(__dirname, "..", "..");
const UPLOAD_LIMITS = path.join(REPO_ROOT, "api/src/Documents/Document/Domain/UploadLimits.php");
const EVIDENCE_KIND = path.join(
  REPO_ROOT,
  "api/src/Backoffice/BankAccount/Domain/Enum/BankAccountEvidenceKind.php",
);

const MAX_ORIGINAL_BYTES = /public const int MAX_ORIGINAL_BYTES = ([\d_]+);/;
const ENUM_CASE = /case (\w+) = '([^']+)';/g;
const MATCH_ARM = /self::(\w+) => '([^']+)'/g;

function read(file: string): string {
  if (!existsSync(file)) {
    throw new Error(
      `${path.relative(REPO_ROOT, file)} is missing. This gate reads the API's own declaration of ` +
        `what the PWA mirrors — update the path rather than deleting the test.`,
    );
  }
  return readFileSync(file, "utf8");
}

/** The `self::Name => 'value'` arms of one method's `match`, keyed by case name. */
function arms(source: string, method: string): Map<string, string> {
  const start = source.indexOf(`function ${method}()`);
  if (start < 0) throw new Error(`BankAccountEvidenceKind declares no ${method}().`);
  const end = source.indexOf("};", start);
  const body = source.slice(start, end);
  return new Map([...body.matchAll(MATCH_ARM)].map((m) => [m[1], m[2]]));
}

function apiKinds(): Map<string, string> {
  return new Map([...read(EVIDENCE_KIND).matchAll(ENUM_CASE)].map((m) => [m[1], m[2]]));
}

describe("the PWA mirrors of the bank-account evidence contract", () => {
  it("read a non-empty universe from the API, so every row below is about something", () => {
    expect(read(UPLOAD_LIMITS)).toMatch(MAX_ORIGINAL_BYTES);
    expect(apiKinds().size).toBeGreaterThan(0);
  });

  it("caps an original at the API's MAX_ORIGINAL_BYTES", () => {
    const declared = MAX_ORIGINAL_BYTES.exec(read(UPLOAD_LIMITS))?.[1] ?? "";

    expect(MAX_DOCUMENT_BYTES).toBe(Number(declared.replaceAll("_", "")));
  });

  it("knows exactly the API's evidence kinds", () => {
    expect([...BANK_ACCOUNT_EVIDENCE_KINDS].sort()).toEqual([...apiKinds().values()].sort());
  });

  it("files each kind under exactly the retention terms the API verifies", () => {
    const source = read(EVIDENCE_KIND);
    const reasons = arms(source, "retentionReason");
    const bases = arms(source, "legalBasis");

    for (const [caseName, kind] of apiKinds()) {
      expect(reasons.get(caseName), `retentionReason() arm for ${caseName}`).toBeDefined();
      expect(bases.get(caseName), `legalBasis() arm for ${caseName}`).toBeDefined();
      expect(EVIDENCE_RETENTION[kind as keyof typeof EVIDENCE_RETENTION]).toEqual({
        retentionReason: reasons.get(caseName),
        legalBasis: bases.get(caseName),
      });
    }
  });
});
