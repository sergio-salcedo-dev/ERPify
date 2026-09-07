<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Finds a docblock that documents nothing because another docblock stands between it and the declaration.
 *
 * PHP binds only the LAST doc comment before a declaration, so the earlier one is inert: reflection, IDEs
 * and PHPStan all read past it. Nothing else in the toolchain reports this — `php-cs-fixer --dry-run` was
 * measured returning exit 0 over a live instance — so a stale block survives every gate while reading like
 * current documentation, and a `@return` on it is a type statement asserted with the confidence of a true
 * one over a declaration it does not describe.
 *
 * **Read with `token_get_all()` rather than a line matcher, and the difference is not cosmetic.** The
 * tokenizer answers with `T_DOC_COMMENT` tokens, so a `/**` inside a string, a heredoc or a `//` comment is
 * never one — where a line-oriented probe cannot tell those apart.
 *
 * **Declared blind spot:** PHP's scanner requires whitespace after the opener, so `/**text*\/` is a
 * `T_COMMENT` and not a doc comment at all. Such a block reads as documentation to a person, binds nothing,
 * and is invisible here — measured, and pinned as a boundary by the rules gate rather than left implicit.
 *
 * @internal test support
 */
final class StackedDocblocks
{
    /**
     * Tokens that may stand between two doc comments without separating them.
     *
     * Each is measured against `getDocComment()`, and the attribute case is measured DISCRIMINATINGLY:
     * observing that the later block still binds is consistent both with an attribute being transparent and
     * with it resetting the doc comment, since either leaves the later one bound. The experiment that tells
     * them apart is a single block followed by an attribute and no second block — `/** A *\/ #[Attr] f()`
     * binds `A`, so the attribute is transparent. Attributes are consumed by the loop rather than listed
     * here, because an attribute is a token GROUP and not a token.
     *
     * @var non-empty-list<int>
     */
    private const array TRANSPARENT = [T_WHITESPACE, T_COMMENT, T_CLOSE_TAG, T_INLINE_HTML, T_OPEN_TAG];

    /**
     * The line of every doc comment that another doc comment supersedes, in file order.
     *
     * @return list<int>
     */
    public static function inSource(string $source): array
    {
        $lines = [];
        $pending = null;

        foreach (self::withoutAttributeGroups(\token_get_all($source)) as $token) {
            if (!\is_array($token)) {
                $pending = null;

                continue;
            }

            if (T_DOC_COMMENT === $token[0]) {
                if (null !== $pending) {
                    $lines[] = $pending;
                }

                $pending = $token[2];

                continue;
            }

            if (!\in_array($token[0], self::TRANSPARENT, true)) {
                $pending = null;
            }
        }

        return $lines;
    }

    /**
     * The token stream with every attribute group dropped whole.
     *
     * An attribute is a token GROUP and not a token — `#[Deprecated]` is `T_ATTRIBUTE`, `T_STRING` and a
     * bare `]` — so listing its opener among the transparent tokens leaves the rest of the group standing
     * between the two blocks. Removing the group here keeps the search above about doc comments alone.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function withoutAttributeGroups(array $tokens): array
    {
        $kept = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($depth > 0) {
                $depth += self::bracketDelta($token);

                continue;
            }

            if (\is_array($token) && T_ATTRIBUTE === $token[0]) {
                $depth = 1;

                continue;
            }

            $kept[] = $token;
        }

        // Only lexically broken source can leave a group open, and `token_get_all()` does not validate. The
        // tail of such a file is dropped entirely, so failing loudly is the difference between "nothing to
        // report" and "nothing was read".
        if (0 !== $depth) {
            throw new RuntimeException('An attribute group never closed, so the rest of this source was not read.');
        }

        return $kept;
    }

    /**
     * @return list<int>
     */
    public static function inFile(string $path): array
    {
        $source = \file_get_contents($path);

        // Never `[]` on a failed read: the caller cannot tell that from a clean file, and a check that
        // quietly does nothing when its input is unreadable reports the same green as a real pass.
        if (!\is_string($source)) {
            throw new RuntimeException(\sprintf('Cannot read %s, so it was never checked.', $path));
        }

        return self::inSource($source);
    }

    /**
     * Nesting inside an attribute — `#[Foo([1, 2])]`, `#[Foo(new Bar())]` — arrives as bare `[` and `]`
     * characters, so the group ends at the `]` that balances its opener rather than at the first one seen.
     * A bracket inside a string literal arrives inside that string's own token and moves nothing.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function bracketDelta(array|string $token): int
    {
        if (\is_array($token)) {
            return T_ATTRIBUTE === $token[0] ? 1 : 0;
        }

        return match ($token) {
            '[' => 1,
            ']' => -1,
            default => 0,
        };
    }
}
