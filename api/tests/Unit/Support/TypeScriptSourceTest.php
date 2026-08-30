<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Support;

use Erpify\Tests\Support\TypeScriptSource;
use Erpify\Tests\Support\UnsafeTypeScriptSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three parity gates decide what they can see through this helper, and both of its directions cost
 * something.
 *
 * Reading a comment as code is the loud failure: the files it reads spell their subject out in prose as
 * well as in code, so a gate would go red over a correct file. **Cutting code as a comment is the quiet
 * one**, and it is what these cases are for — what disappears is exactly the declaration the gate exists to
 * demand, so the build stays green while the control stops existing.
 *
 * The cases come in three groups, and the middle one is the reason the helper is a single pass. **Code must
 * survive**: a URL in a string, a block marker inside a literal, and a `/*` sitting inside a `//` are all
 * things the file is entitled to contain, and each was measured being cut out when the strip and the check
 * that guarded it were two separate readings of the same text. **What is not modelled must refuse**: the
 * JSX apostrophe and the regex literal are the two shapes that make the pass lose its place, and both were
 * measured corrupting silently before they raised. **And a false refusal must not creep in**: an apostrophe
 * inside a comment is the shape that desynchronises a naive scan and would red a file with nothing wrong
 * with it.
 *
 * Nothing here reads the PWA tree: the three gates already run this helper over the real corpus on every
 * build, so a file that grew an unmodelled construct reds there, where the diagnostic can name which gate
 * stopped being able to check anything.
 *
 * @internal
 */
#[CoversClass(TypeScriptSource::class)]
#[CoversClass(UnsafeTypeScriptSource::class)]
final class TypeScriptSourceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItRefusesWhatItDoesNotModelCases')]
    public function itRefusesWhatItDoesNotModel(string $source, string $expectedInMessage): void
    {
        $this->expectException(UnsafeTypeScriptSource::class);
        $this->expectExceptionMessageMatches('/' . \preg_quote($expectedInMessage, '/') . '/');

        TypeScriptSource::withoutComments($source);
    }

    /**
     * The apostrophe is the live one, and its shape matters: `RedactedValue.tsx` is JSX and this repository
     * requires English copy, whose contractions carry apostrophes. Unrefused, the literal it opens is closed
     * by the next real quote, and from there a `//` inside a URL reads as a comment and truncates the line.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideItRefusesWhatItDoesNotModelCases(): iterable
    {
        yield 'an apostrophe in JSX text, which opens a literal that is not there' => [
            "<p>Don't</p>\nconst URL = 'https://x';\nconst KEEP = 1;\n",
            "the ' literal opened on line 1 reaches the end of its line",
        ];

        yield 'a regex literal, which the pass does not model at all' => [
            "const re = /\\/\\//;\nconst KEEP = 1;\n",
            'line 1 has a `/` where a regex literal would be legal',
        ];

        yield 'a template literal still open at end of file' => [
            "const t = `oops;\nconst KEEP = 1;\n",
            'literal opened on line 1 is still open at the end of the file',
        ];

        yield 'a block comment that is never closed' => [
            "const a = 1;\n/* never closed\nconst KEEP = 1;\n",
            'block comment opened on line 2 is never closed',
        ];
    }

    #[Test]
    #[DataProvider('provideItKeepsEveryByteThatIsNotACommentCases')]
    public function itKeepsEveryByteThatIsNotAComment(string $source, string $expected): void
    {
        $this->assertSame($expected, TypeScriptSource::withoutComments($source));
    }

    /**
     * Each of these was measured losing code when the removal and the check that guarded it were two
     * readings of one text: a pattern-strip cannot see that a `/*` sits inside a `//`, and a scan that skips
     * line comments whole cannot see it either — so between them the block strip ate three lines and nothing
     * raised. One pass cannot hold two opinions about where a comment begins.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideItKeepsEveryByteThatIsNotACommentCases(): iterable
    {
        yield 'a block opener inside a line comment takes only its own line' => [
            "// see /* here\nconst KEEP = 1;\n/* real */\nconst B = 2;\n",
            "\nconst KEEP = 1;\n\nconst B = 2;\n",
        ];

        yield 'a URL in a string is code, not a comment' => [
            "const endpoint = 'https://example.test';\n",
            "const endpoint = 'https://example.test';\n",
        ];

        yield 'block markers inside literals survive' => [
            "const p = \"/* not a comment\";\nconst q = `ends */ here`;\n",
            "const p = \"/* not a comment\";\nconst q = `ends */ here`;\n",
        ];

        yield 'an apostrophe inside a line comment opens nothing' => [
            "// don't read this as a quote\nconst KEEP = 'value';\n",
            "\nconst KEEP = 'value';\n",
        ];

        yield 'an apostrophe inside a block comment opens nothing' => [
            "/* it's fine */\nconst KEEP = 'value';\n",
            "\nconst KEEP = 'value';\n",
        ];

        yield 'a division is not a comment' => [
            "const ratio = width / height;\n",
            "const ratio = width / height;\n",
        ];

        yield 'a JSX closing tag is not a regex literal' => [
            "const x = <p>hi</p>;\n",
            "const x = <p>hi</p>;\n",
        ];

        yield 'a self-closing JSX element is not a regex literal' => [
            "const x = <br />;\n",
            "const x = <br />;\n",
        ];

        yield 'an escaped quote does not close its literal' => [
            "const a = 'it\\'s fine'; // gone\n",
            "const a = 'it\\'s fine'; \n",
        ];

        yield 'a block comment spanning lines goes whole' => [
            "const a = 1;\n/*\n * prose\n */\nconst b = 2;\n",
            "const a = 1;\n\nconst b = 2;\n",
        ];
    }
}
