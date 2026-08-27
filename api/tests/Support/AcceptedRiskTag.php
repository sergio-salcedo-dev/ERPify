<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * One `@accepted-risk` occurrence, well-formed or not, with enough position information that a caller never
 * has to re-parse the source to report where it lives.
 *
 * `issueNumber` is `null` when `rawTag` matched the literal token but not the strict grammar
 * (`@accepted-risk#123`, `#0`, `#0123`, …) — a near-miss the gate must fail loudly rather than silently
 * ignore, since a malformed tag is exactly as invisible to audit as no tag at all.
 *
 * @internal test support
 */
final readonly class AcceptedRiskTag
{
    public function __construct(
        public string $sourceFile,
        public int $line,
        public int $paragraphStartLine,
        public int $paragraphEndLine,
        public ?int $issueNumber,
        public string $rawTag,
    ) {
    }
}
