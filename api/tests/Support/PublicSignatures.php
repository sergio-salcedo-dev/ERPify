<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Every public method signature declared under a directory, read as TOKENS.
 *
 * Reflection is the obvious alternative and it is the wrong instrument here: it needs the class to load, so
 * it answers about a container-resolvable universe rather than about the tree, and a signature written in a
 * file nothing autoloads would simply not appear. A line-oriented matcher is the other alternative, and the
 * shapes it misses are ordinary rather than exotic — a parameter list wrapped over five lines, a promoted
 * constructor property carrying its visibility, a default value holding a comma, an attribute sitting
 * between the docblock and the keyword. The tokeniser knows all four.
 *
 * "Public" includes a method declaring no visibility at all, because PHP does. Reading only explicit
 * `public` would let `function read(string $path)` pass a gate written to refuse exactly that.
 *
 * Reading ONE signature is {@see SignatureReader}'s job; this class answers which methods are public.
 *
 * Read only: each caller layers its own matching on top.
 *
 * @internal test support
 */
final class PublicSignatures
{
    /** Modifiers that may sit between a visibility keyword and `function`, and must be walked past. */
    private const array MODIFIERS = [T_FINAL, T_ABSTRACT, T_STATIC, T_READONLY];

    private const array IGNORABLE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * @return list<array{file: string, method: string, parameters: list<string>, types: list<string>}>
     */
    public static function under(string $directory): array
    {
        $signatures = [];

        foreach (ApiSourceFiles::phpFiles($directory) as $file) {
            foreach (self::inSource((string) \file_get_contents($file->getPathname())) as $signature) {
                $signatures[] = ['file' => $file->getPathname(), ...$signature];
            }
        }

        return $signatures;
    }

    /**
     * @return list<array{method: string, parameters: list<string>, types: list<string>}>
     */
    public static function inSource(string $source): array
    {
        $tokens = \token_get_all($source);
        $signatures = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || T_FUNCTION !== $token[0]) {
                continue;
            }

            $name = self::methodNameAt($tokens, $index);

            // A closure or a first-class callable reference declares no member signature. `fn` lexes as
            // T_FN and never reaches here at all.
            if (null === $name || !self::isPublicAt($tokens, $index)) {
                continue;
            }

            $signatures[] = ['method' => $name, ...SignatureReader::at($tokens, $index)];
        }

        return $signatures;
    }

    /**
     * Two shapes made a method DISAPPEAR from the scan entirely rather than be read wrongly, which is the
     * worse direction: the file still yields other methods, so the non-vacuity guard never notices.
     *
     * **A by-reference return.** `public function &bytes(): string` puts a literal `&` between `function`
     * and the name, so requiring `T_STRING` immediately after found nothing.
     *
     * **A semi-reserved method name.** PHP allows `list`, `print`, `default`, `array`, `match`, `fn` and the
     * rest as METHOD names, and the lexer emits each as its own keyword token — never `T_STRING`. Matched by
     * SHAPE rather than against a list of keywords: an enumeration is a list somebody has to keep, and PHP
     * adds keywords. Anything the lexer produced whose text is a valid identifier is a name here, which is
     * exactly the language's own rule.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function methodNameAt(array $tokens, int $index): ?string
    {
        $next = self::nextMeaningful($tokens, $index + 1);

        if (self::isReferenceMarker($next)) {
            $next = self::nextMeaningful($tokens, self::indexAfterReference($tokens, $index + 1));
        }

        if (!\is_array($next)) {
            return null;
        }

        return 1 === \preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $next[1]) ? $next[1] : null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function indexAfterReference(array $tokens, int $from): int
    {
        $total = \count($tokens);

        for ($cursor = $from; $cursor < $total; ++$cursor) {
            if (self::isReferenceMarker($tokens[$cursor] ?? null)) {
                return $cursor + 1;
            }
        }

        return $from;
    }

    /**
     * Since PHP 8.1 an `&` is not the single-character token it reads as. The lexer decides by what FOLLOWS
     * it — `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG` before a variable, `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`
     * before anything else — so the `&` of a by-reference RETURN arrives as an array token while a matcher
     * comparing against the string `'&'` sees nothing. Compared on the token's TEXT, which is `&` in every
     * spelling and does not depend on which of those constants the running version emits.
     *
     * @param array{int, string, int}|string|null $token
     */
    private static function isReferenceMarker(array|string|null $token): bool
    {
        return '&' === (\is_array($token) ? $token[1] : $token);
    }

    /**
     * Walks backwards past the modifiers and the trivia. Anything else — a brace, a semicolon, the `]`
     * closing an attribute — means no visibility was written, which in PHP means public.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isPublicAt(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
            $token = $tokens[$cursor] ?? null;

            if (!\is_array($token)) {
                return true;
            }

            if (\in_array($token[0], [T_PRIVATE, T_PROTECTED], true)) {
                return false;
            }

            if (T_PUBLIC === $token[0]) {
                return true;
            }

            if (!\in_array($token[0], [...self::IGNORABLE, ...self::MODIFIERS], true)) {
                return true;
            }
        }

        return true;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{int, string, int}|string|null
     */
    private static function nextMeaningful(array $tokens, int $from): array|string|null
    {
        $counter = \count($tokens);

        for ($cursor = $from; $cursor < $counter; ++$cursor) {
            $token = $tokens[$cursor] ?? null;

            if (null === $token) {
                return null;
            }

            if (!\is_array($token) || !\in_array($token[0], self::IGNORABLE, true)) {
                return $token;
            }
        }

        return null;
    }
}
