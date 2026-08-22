<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Unit\Gate\Support\StepVocabularyRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every way `api/.behat-step-vocabulary` can be malformed, driven with a string.
 *
 * The gate over the committed file can only ever exercise the shape that file happens to have, so its
 * four refusals were assertions nothing could reach — a parser whose guards are unfalsifiable, inside
 * the mechanism whose whole subject is assertions that cannot fail.
 *
 * The repeated-pattern case is the one with teeth. PHP array assignment keeps the later value for a
 * repeated key, so without the refusal one of the two classifications is never evaluated while the
 * file still reads as complete and the counts still add up.
 *
 * {@see CoversNothing} because the subject is test infrastructure — `tests/` sits outside the coverage
 * allowlist, so there is no production line here to credit.
 *
 * @internal
 */
#[CoversNothing]
final class StepVocabularyRegistryTest extends TestCase
{
    public function testItReadsPatternsPastCommentsAndBlankLines(): void
    {
        $registry = StepVocabularyRegistry::parse(
            "# a header\n\nI do a thing => used\n\n# another comment\nI do another => idle\n",
        );

        $this->assertSame(['I do a thing' => 'used', 'I do another' => 'idle'], $registry);
    }

    public function testAPatternContainingTheSeparatorSplitsOnTheLastOne(): void
    {
        $this->assertSame(
            ['the mapping :a => :b holds' => 'idle'],
            StepVocabularyRegistry::parse('the mapping :a => :b holds => idle'),
        );
    }

    public function testItRefusesALineItCannotRead(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Malformed line');

        StepVocabularyRegistry::parse("I do a thing => used\nI am not classified at all\n");
    }

    public function testItRefusesAClassificationItDoesNotKnow(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Unknown classification "probably"');

        StepVocabularyRegistry::parse('I do a thing => probably');
    }

    public function testItRefusesAPatternClassifiedTwice(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Pattern classified twice: I do a thing');

        StepVocabularyRegistry::parse("I do a thing => used\nI do a thing => idle\n");
    }

    public function testItRefusesAFileCarryingNoClassification(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('carries no classification at all');

        StepVocabularyRegistry::parse("# only comments\n\n# and blank lines\n");
    }
}
