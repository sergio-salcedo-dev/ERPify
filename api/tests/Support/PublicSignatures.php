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
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function methodNameAt(array $tokens, int $index): ?string
    {
        $next = self::nextMeaningful($tokens, $index + 1);

        return \is_array($next) && T_STRING === $next[0] ? $next[1] : null;
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
