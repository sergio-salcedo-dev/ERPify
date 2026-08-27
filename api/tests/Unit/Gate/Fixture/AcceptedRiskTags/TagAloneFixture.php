<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\AcceptedRiskTags;

/**
 * The real rationale lives in the paragraph above; the tag sits alone in its own paragraph below it, with a
 * blank comment line between the two. The gate must FAIL on the content floor here — never on a claim about
 * "the disposition sentence", which it cannot identify.
 *
 * Accepted residual, not fixed, because the failure mode this risk describes has no cheap detective control
 * and the cost of closing it now outweighs the benefit.
 *
 * @accepted-risk #501
 *
 * @internal
 */
final class TagAloneFixture
{
}
