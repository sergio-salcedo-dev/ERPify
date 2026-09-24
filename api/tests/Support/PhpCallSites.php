<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Method bodies and `$this-><property>-><method>(` call sites of a PHP class source, read from the token stream.
 *
 * Tokens rather than text, for the reason {@see PhpSource} gives and one more: a string literal is ONE token,
 * so a call spelled inside a string, a heredoc or a nowdoc is never a call, and whitespace or a comment between
 * the tokens of a chain is skipped, so `$this->x\n    ->y(` — the shape `php-cs-fixer` produces past 120
 * columns — still is one. What it deliberately does not do is follow anything: a call through a local alias,
 * a helper method or a trait is simply absent from the body that reaches it.
 *
 * @internal test support
 */
final class PhpCallSites
{
    private const array TRIVIA = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG];

    /**
     * Every named method a class source declares, mapped to the source of its body (braces included).
     * Closures stay inside the body that declares them; an abstract method has no body and is absent.
     *
     * @return array<string, string>
     */
    public static function methodBodies(string $classSource): array
    {
        $tokens = \token_get_all($classSource);
        $count = \count($tokens);
        $bodies = [];

        for ($i = 0; $i < $count; ++$i) {
            $name = self::is(self::at($tokens, $i), T_FUNCTION) ? self::declaredName($tokens, $i + 1) : null;

            if (null === $name) {
                continue;
            }

            $open = self::bodyStart($tokens, $i + 1);

            if (null === $open) {
                continue;
            }

            [$bodies[$name], $i] = self::balancedBlock($tokens, $open);
        }

        return $bodies;
    }

    /**
     * `$this-><property>-><method>(` call sites in a body, in source order.
     *
     * @return list<array{string, string}> [property, method]
     */
    public static function calls(string $body): array
    {
        $tokens = \array_values(\array_filter(
            \token_get_all('<?php ' . $body),
            static fn (array|string $token): bool => !self::isTrivia($token),
        ));
        $calls = [];

        foreach (\array_keys($tokens) as $i) {
            $call = self::callAt(\array_slice($tokens, $i, 6));

            if (null !== $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $sequence
     *
     * @return array{string, string}|null
     */
    private static function callAt(array $sequence): ?array
    {
        if (6 !== \count($sequence)) {
            return null;
        }

        [$receiver, $arrow, $property, $secondArrow, $method, $parenthesis] = $sequence;
        $shape = self::is($receiver, T_VARIABLE) && '$this' === self::text($receiver)
            && self::isObjectOperator($arrow) && self::isObjectOperator($secondArrow)
            && self::is($property, T_STRING) && self::is($method, T_STRING)
            && '(' === $parenthesis;

        return $shape ? [self::text($property), self::text($method)] : null;
    }

    /**
     * The method name after `function` (a by-reference `&` skipped), or null for a closure.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function declaredName(array $tokens, int $from): ?string
    {
        $j = self::nextSignificant($tokens, $from);

        if ('&' === self::text(self::at($tokens, $j))) {
            $j = self::nextSignificant($tokens, $j + 1);
        }

        $token = self::at($tokens, $j);

        return \is_array($token) && 1 === \preg_match('/^[A-Za-z_]\w*$/', $token[1]) ? $token[1] : null;
    }

    /**
     * The index of the body's opening brace, or null when a `;` ends the declaration first.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function bodyStart(array $tokens, int $from): ?int
    {
        $count = \count($tokens);

        for ($k = $from; $k < $count; ++$k) {
            $text = self::text(self::at($tokens, $k));

            if ('{' === $text || ';' === $text) {
                return '{' === $text ? $k : null;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{string, int} the block's source and the index of its closing brace
     */
    private static function balancedBlock(array $tokens, int $open): array
    {
        $depth = 0;
        $source = '';
        $count = \count($tokens);

        for ($k = $open; $k < $count; ++$k) {
            $token = self::at($tokens, $k);
            $text = self::text($token);
            $source .= $text;

            if (self::opensBlock($token)) {
                ++$depth;
            } elseif ('}' === $text && 0 === --$depth) {
                return [$source, $k];
            }
        }

        return [$source, $count];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): int
    {
        $count = \count($tokens);

        while ($from < $count && self::isTrivia(self::at($tokens, $from))) {
            ++$from;
        }

        return $from;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string
     */
    private static function at(array $tokens, int $index): array|string
    {
        return $tokens[$index] ?? '';
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function opensBlock(array|string $token): bool
    {
        return '{' === $token
            || (\is_array($token) && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isTrivia(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], self::TRIVIA, true);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function is(array|string $token, int $id): bool
    {
        return \is_array($token) && $id === $token[0];
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function isObjectOperator(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }
}
