<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The gates that sweep `api/src` as text decide what they can see through this helper, so both of its
 * directions cost something and neither is covered by using it.
 *
 * Reading a comment as code is the loud failure: prose about what a file does NOT do becomes a declaration,
 * and a gate goes red over a correct file. **Stripping code as a comment is the quiet one**, and it is the
 * one worth tests: a declaration that disappears takes with it the gate's reason to demand anything, so the
 * build stays green while the control it protects stops existing. `#` opens a comment and `#[` opens an
 * attribute — one character apart, which is exactly the confusion a pattern-stripper makes and the tokeniser
 * does not.
 *
 * @internal
 */
#[CoversClass(PhpSource::class)]
final class PhpSourceTest extends TestCase
{
    #[Test]
    public function itKeepsAnAttributeThatFollowsAHashComment(): void
    {
        // `#` is a comment in PHP and `#[` is an attribute. A stripper keyed on `#` eats the declaration.
        $code = PhpSource::withoutComments(<<<'PHP'
            <?php
            # a hash comment, one character away from the attribute below
            #[AsSchedule('alpha')]
            final class C {}
            PHP);

        $this->assertStringContainsString("#[AsSchedule('alpha')]", $code);
        $this->assertStringNotContainsString('a hash comment', $code);
    }

    #[Test]
    public function itKeepsAnAttributeCarryingATrailingComment(): void
    {
        // The comment ends the line the declaration lives on; a line-oriented strip takes both.
        $code = PhpSource::withoutComments(<<<'PHP'
            <?php
            #[AsSchedule('alpha')] // why this one is daily
            final class C {}
            PHP);

        $this->assertStringContainsString("#[AsSchedule('alpha')]", $code);
        $this->assertStringNotContainsString('why this one is daily', $code);
    }

    #[Test]
    public function itKeepsAnAttributeWrittenAcrossSeveralLines(): void
    {
        $code = PhpSource::withoutComments(<<<'PHP'
            <?php
            #[AsSchedule(
                name: 'alpha',
            )]
            final class C {}
            PHP);

        $this->assertStringContainsString('AsSchedule', $code);
        $this->assertStringContainsString("name: 'alpha'", $code);
    }

    #[Test]
    public function itLeavesAStringThatLooksLikeACommentAlone(): void
    {
        // `//` and `#` inside a literal are neither comment nor code to strip, and a sweep that lost them
        // would change what the file says without anything reporting it.
        $code = PhpSource::withoutComments(<<<'PHP'
            <?php
            $a = '// not a comment';
            $b = "# neither is this";
            PHP);

        $this->assertStringContainsString('// not a comment', $code);
        $this->assertStringContainsString('# neither is this', $code);
    }

    #[Test]
    public function itRemovesBothCommentShapes(): void
    {
        $code = PhpSource::withoutComments(<<<'PHP'
            <?php
            /** a docblock */
            // a line comment
            /* a block comment */
            final class C {}
            PHP);

        $this->assertStringNotContainsString('docblock', $code);
        $this->assertStringNotContainsString('line comment', $code);
        $this->assertStringNotContainsString('block comment', $code);
        $this->assertStringContainsString('final class C', $code);
    }
}
