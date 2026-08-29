<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reading TypeScript source as text, for the gates that compare a PHP site against its PWA counterpart.
 *
 * Stripping comments first is load-bearing rather than defensive in the three gates that use it: each of the
 * files they read spells its subject out in prose as well as in code, so a gate reading raw bytes could
 * satisfy itself from a docblock while the rendered literal had drifted. {@see PhpSource} does the same job
 * on the other side and CANNOT be reused — it tokenises with `token_get_all`, which is a PHP lexer.
 *
 * **One walk decides everything, and that is the whole design.** A validator that answers "would the strip
 * corrupt this?" separately from the strip itself is two readings of one text, and two readings disagree:
 * measured, a `/*` sitting INSIDE a `//` comment is invisible to a scanner that skips line comments whole,
 * while a `#/\*.*?\*\/#s` pass sees it and eats every line down to the next `*​/` — silently deleting code the
 * gate then asserts over. So the comment removal happens in the same pass that tracks literals, and the two
 * cannot hold different opinions about where a comment begins.
 *
 * **What the pass does not model, it REFUSES.** Not "parses better": a hand-written TypeScript lexer is
 * harder to get right than what it replaces, and a subtly wrong one fails silently, which is the failure
 * this class exists to end. Three refusals, each closing a measured corruption:
 *
 *   - A `'` or `"` literal that reaches the end of its line unterminated. JavaScript does not continue those
 *     across a newline without an escape, so an unterminated one means the pass has lost its place — an
 *     apostrophe in JSX text (`<p>Don't</p>`) is the live shape, and left alone it opened a literal that the
 *     next real quote closed, after which a `//` inside a URL read as a comment and truncated the line.
 *   - A template literal or a block comment still open at end of file, which is the same loss one construct
 *     over.
 *   - A `/` in code position where a regex literal would be legal. Regex literals are not modelled at all,
 *     and `/\/\//` was measured being read as a line comment.
 *
 * Measured on the five files the three calling gates read: all five accepted, zero refusals. Every
 * apostrophe in them sits inside a block comment, which the pass consumes whole — so the corpus is green
 * because of the design rather than in spite of it.
 *
 * @internal test support
 */
final class TypeScriptSource
{
    private const string QUOTES = '\'"`';

    /**
     * Characters that can END an expression. A `/` after one of them is a division; after anything else a
     * regex literal would be legal, and the pass refuses rather than guess. `<` is here because `</div>`
     * closes a JSX element, which is far more common in this corpus than `a < /re/.test(b)`.
     */
    private const string DIVISION_AFTER = ')]}<';

    /**
     * The source with every comment removed, and nothing else changed.
     *
     * @throws UnsafeTypeScriptSource when the pass meets a construct it does not model
     */
    public static function withoutComments(string $source): string
    {
        $length = \strlen($source);
        $code = '';
        $offset = 0;

        while ($offset < $length) {
            $character = $source[$offset];
            $pair = \substr($source, $offset, 2);

            if ('//' === $pair) {
                $offset = self::endOfLineComment($source, $offset, $length);

                continue;
            }

            if ('/*' === $pair) {
                $offset = self::endOfBlockComment($source, $offset, $length);

                continue;
            }

            if (\str_contains(self::QUOTES, $character)) {
                $literal = self::literalAt($source, $offset, $length);
                $code .= $literal;
                $offset += \strlen($literal);

                continue;
            }

            if ('/' === $character) {
                self::refuseARegexLiteralAt($source, $offset);
            }

            $code .= $character;
            ++$offset;
        }

        return $code;
    }

    /** The offset just past the comment, keeping its terminating newline as part of the code. */
    private static function endOfLineComment(string $source, int $offset, int $length): int
    {
        $newline = \strpos($source, "\n", $offset);

        return false === $newline ? $length : $newline;
    }

    private static function endOfBlockComment(string $source, int $offset, int $length): int
    {
        $closer = \strpos($source, '*/', $offset + 2);

        if (false === $closer) {
            throw UnsafeTypeScriptSource::unterminatedBlockComment(self::lineAt($source, $offset));
        }

        return \min($closer + 2, $length);
    }

    /**
     * The literal starting at this offset, delimiter to delimiter, returned verbatim so a comment marker
     * inside it survives the strip instead of being cut out of the code around it.
     */
    private static function literalAt(string $source, int $offset, int $length): string
    {
        $delimiter = $source[$offset];
        $openedOnLine = self::lineAt($source, $offset);
        $multiline = '`' === $delimiter;

        for ($cursor = $offset + 1; $cursor < $length; ++$cursor) {
            $character = $source[$cursor];

            if ('\\' === $character) {
                ++$cursor;

                continue;
            }

            if ($character === $delimiter) {
                return \substr($source, $offset, $cursor - $offset + 1);
            }

            if (!$multiline && "\n" === $character) {
                throw UnsafeTypeScriptSource::unterminatedLineLiteral($openedOnLine, $delimiter);
            }
        }

        throw UnsafeTypeScriptSource::unterminatedLiteral($openedOnLine, $delimiter);
    }

    private static function refuseARegexLiteralAt(string $source, int $offset): void
    {
        $before = \rtrim(\substr($source, 0, $offset));
        $previous = '' === $before ? '' : $before[-1];

        if ('' !== $previous && (\str_contains(self::DIVISION_AFTER, $previous) || \preg_match('/[\w$]/', $previous))) {
            return;
        }

        throw UnsafeTypeScriptSource::unmodelledRegexLiteral(self::lineAt($source, $offset));
    }

    private static function lineAt(string $source, int $offset): int
    {
        return \substr_count($source, "\n", 0, $offset) + 1;
    }
}
