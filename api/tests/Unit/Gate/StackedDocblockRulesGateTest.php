<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\StackedDocblocks;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifies the superseded-docblock rule against synthetic fixtures under {@see Fixture\StackedDocblocks},
 * so {@see StackedDocblockGateTest} can trust it over the real tree without re-deriving it.
 *
 * Per docs/rules/testing.md ("assert the seed before asserting the absence"): every negative case below sits
 * beside a positive one proving the same machinery reports the violation, because a detector that finds
 * nothing and a detector that cannot find anything report the same green. The first probe written for this
 * defect was measured silent on the very instance it had been built from.
 *
 * The line numbers below are exact on purpose, and they couple these assertions to the fixtures' layout:
 * the fixtures sit inside php-cs-fixer's finder and `make php.quality` runs it in APPLY mode, so a future
 * rule that reflows one shifts every expectation here and the red names this gate rather than the fixer.
 *
 * @internal test support
 */
#[CoversNothing]
final class StackedDocblockRulesGateTest extends TestCase
{
    #[Test]
    public function twoAdjacentDocblocksReportTheFirst(): void
    {
        $this->assertSame([7], $this->linesIn('StackedFixture.php'));
    }

    /**
     * Everything PHP steps over on its way to a declaration, each measured against `getDocComment()` rather
     * than assumed. The attribute cases are the ones a line-oriented probe cannot see, and the two bracket
     * cases exist because an attribute group ends at the `]` that BALANCES its opener — a scan stopping at
     * the first one puts the group's tail back between the blocks and they stop pairing.
     */
    #[Test]
    #[DataProvider('provideATransparentSeparatorLeavesTheEarlierDocblockInertCases')]
    public function aTransparentSeparatorLeavesTheEarlierDocblockInert(string $fixture, int $line): void
    {
        $this->assertSame([$line], $this->linesIn($fixture));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideATransparentSeparatorLeavesTheEarlierDocblockInertCases(): iterable
    {
        yield 'an attribute' => ['AttributeBetweenFixture.php', 11];
        yield 'an attribute holding nested brackets' => ['NestedAttributeBetweenFixture.php', 12];
        yield 'an attribute holding a bracket in a string' => ['BracketInAttributeStringFixture.php', 11];
        yield 'an ordinary line comment' => ['LineCommentBetweenFixture.php', 9];
    }

    /**
     * Reporting only the first of three would leave the middle one behind, which is how a sweep closes an
     * instance and reports the file clean while it still holds one.
     */
    #[Test]
    public function everySupersededBlockIsReportedAndNotJustTheFirst(): void
    {
        $this->assertSame([9, 12], $this->linesIn('TripleStackedFixture.php'));
    }

    #[Test]
    public function docblocksBindingTheirOwnDeclarationsAreNotReported(): void
    {
        $this->assertSame([], $this->linesIn('SeparateDeclarationsFixture.php'));
    }

    /**
     * The reason the rule reads tokens and not lines. Doc-comment syntax inside a single-quoted string, a
     * double-quoted string and a nowdoc is data; a line matcher cannot tell it from a declaration, and this
     * fixture stacks two of them to make the difference reportable rather than theoretical.
     */
    #[Test]
    public function docCommentSyntaxInsideAStringLiteralIsNotADocblock(): void
    {
        $this->assertSame([], $this->linesIn('DocblockInLiteralFixture.php'));
    }

    /**
     * A file the sweep cannot read must fail loudly. Answering `[]` reports it as clean, and the caller
     * cannot tell that from a file it actually checked — the failure mode this whole gate exists to refuse.
     */
    #[Test]
    public function anUnreadableFileFailsLoudlyRatherThanReportingClean(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/was never checked/');

        StackedDocblocks::inFile(\sys_get_temp_dir() . '/erpify-absent-' . \uniqid());
    }

    /**
     * A `?>` boundary is source rather than a fixture file, because `@PSR12`'s `no_closing_tag` would
     * rewrite the fixture and delete the case. Measured: `getDocComment()` binds the later block across it,
     * so the earlier one is inert exactly as anywhere else.
     */
    #[Test]
    public function aCloseTagBoundaryDoesNotSeparateTwoDocblocks(): void
    {
        $source = "<?php\n/** FIRST */\n?>\ninline html\n<?php\n/** SECOND */\nfunction f(): void {}\n";

        $this->assertSame([2], StackedDocblocks::inSource($source));
    }

    /**
     * The declared blind spot, pinned so it is a boundary rather than an oversight: PHP's scanner needs
     * whitespace after the opener, so `/**text*\/` is a `T_COMMENT`. It reads as documentation to a person
     * and binds nothing, and this rule reports it as nothing — measured with `token_name()`.
     */
    #[Test]
    public function aDocCommentOpenerWithoutWhitespaceIsNotADocblock(): void
    {
        $source = "<?php\n/**no space*/\n/** SECOND */\nfunction f(): void {}\n";

        $this->assertSame([], StackedDocblocks::inSource($source));
    }

    /**
     * @return list<int>
     */
    private function linesIn(string $fixture): array
    {
        $path = __DIR__ . '/Fixture/StackedDocblocks/' . $fixture;
        $this->assertTrue(\is_file($path), \sprintf('The fixture %s is not there.', $path));

        return StackedDocblocks::inFile($path);
    }
}
