<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\AcceptedRiskTags;

/**
 * Three grammar near-misses in three separate paragraphs, none matching the strict
 * `@accepted-risk[ \t]+#[1-9][0-9]*` form. Each must be reported as malformed (a null issue number), never
 * silently ignored.
 *
 * No space before the hash. @accepted-risk#123
 *
 * A leading zero is not a valid issue number. @accepted-risk #0
 *
 * Same for a zero-padded number. @accepted-risk #0123
 *
 * @internal
 */
final class MalformedGrammarFixture
{
}
