<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Raised when {@see TypeScriptSource::withoutComments()} meets a construct its single pass does not model.
 * It exists so that limit is a red with a line number instead of a gate quietly asserting over text with
 * code cut out of it.
 *
 * @internal test support
 */
final class UnsafeTypeScriptSource extends RuntimeException
{
    public static function unterminatedLineLiteral(int $line, string $delimiter): self
    {
        return new self(\sprintf(
            'This TypeScript source cannot be comment-stripped safely: the %s literal opened on line %d '
            . 'reaches the end of its line unterminated. JavaScript does not continue one across a newline '
            . 'without an escape, so the pass has lost track of what is code — an apostrophe in JSX text is '
            . 'the usual cause, and left alone it opens a literal the next real quote closes, after which a '
            . '`//` inside a URL reads as a comment. Rewrite the text (`&apos;`, or a braced string), or '
            . 'give the reading gate a strip that models JSX.',
            $delimiter,
            $line,
        ));
    }

    public static function unterminatedLiteral(int $line, string $delimiter): self
    {
        return new self(\sprintf(
            'This TypeScript source cannot be comment-stripped safely: the %s literal opened on line %d is '
            . 'still open at the end of the file, so the pass no longer knows which text is code. Refusing '
            . 'rather than guessing.',
            $delimiter,
            $line,
        ));
    }

    public static function unterminatedBlockComment(int $line): self
    {
        return new self(\sprintf(
            'This TypeScript source cannot be comment-stripped safely: the block comment opened on line %d '
            . 'is never closed, so removing it would take the rest of the file with it.',
            $line,
        ));
    }

    public static function unmodelledRegexLiteral(int $line): self
    {
        return new self(\sprintf(
            'This TypeScript source cannot be comment-stripped safely: line %d has a `/` where a regex '
            . 'literal would be legal, and the pass does not model those — `/\/\//` was measured being '
            . 'read as a line comment, which cuts the rest of the line out of the code. Assign the pattern '
            . 'through `new RegExp(...)`, or give the reading gate a strip that models regex literals.',
            $line,
        ));
    }
}
