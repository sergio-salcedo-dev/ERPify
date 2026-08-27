<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\AcceptedRiskTags;

/**
 * Two related risks accepted in the same paragraph, each with its own tracking issue. Both tags are
 * independently valid, and the paragraph's single content floor is satisfied by the surrounding prose.
 *
 * Accepted residual covering two related failure modes that share one root cause and one rationale,
 * tagged inline (never as its own leading-`@` line) because PHP-CS-Fixer's PHPDoc formatting always
 * blank-line-isolates a line whose first token is `@...`. @accepted-risk #502 @accepted-risk #503
 *
 * @internal
 */
final class MultipleTagsFixture
{
}
