<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Reading PHP source as text, for the gates that deliberately do not use reflection.
 *
 * Several architecture gates sweep `api/src` as text rather than by reflection, and each has its reason: a
 * container that compiles a dead registration hides the defect, and an attribute is present whether or not
 * anything consumes it. What every one of them shares is that the sweep must see CODE — a docblock naming a
 * repository, a call or an attribute states an intention, and an intention must never be able to stand in for
 * the thing itself. Read as raw bytes it can, in both directions: prose about what a file does NOT do becomes
 * a declaration that it does.
 *
 * @internal test support
 */
final class PhpSource
{
    /**
     * The source with every comment and docblock removed, and nothing else changed.
     *
     * Tokenised rather than pattern-stripped: `#[` opens an attribute and `#` opens a comment, `//` inside a
     * string literal is neither, and no regex tells those apart reliably. The tokeniser already knows.
     */
    public static function withoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
