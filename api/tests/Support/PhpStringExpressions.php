<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The string expressions a PHP source spells, rebuilt from its tokens the way a statement is actually written
 * across a codebase rather than the way a single literal holds it.
 *
 * - literals joined with `.` read as one string, so `'UPDATE ' . 'event_store'` is one expression;
 * - `self::NAME` / `static::NAME` resolve when NAME is a string constant declared in the same source
 *   (resolved in declaration order, so a constant may build on one declared above it);
 * - heredoc, nowdoc and double-quoted strings (a `b` binary prefix included) contribute their literal parts,
 *   and every interpolated part becomes an opaque {@see self::GAP}.
 *
 * Whitespace and comments are dropped before anything is read, for the reason {@see PhpSource} gives: a
 * docblock quoting a statement describes it and must never read as one. What it does not resolve — a
 * variable, a call, `sprintf`, a constant of another class — ends the expression rather than guessing.
 *
 * @internal test support
 */
final class PhpStringExpressions
{
    /**
     * Stands in for any part of a string that is not a literal. It is neither a word character nor
     * whitespace, so no word can be completed across it.
     */
    public const string GAP = "\0";

    /** @var list<array{int, string, int}|string> */
    private readonly array $tokens;

    /** @var array<string, string> */
    private array $constants = [];

    public function __construct(string $source)
    {
        $this->tokens = \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $this->resolveConstants();
    }

    public function count(): int
    {
        return \count($this->tokens);
    }

    /**
     * @return array{int, string, int}|string|null
     */
    public function token(int $index): array|string|null
    {
        return $this->tokens[$index] ?? null;
    }

    /**
     * The string expression starting at `$index` and the index just past it, or `[null, $index]` when no
     * string operand starts there.
     *
     * @return array{?string, int}
     */
    public function at(int $index): array
    {
        $parts = [];

        while (true) {
            $operand = $this->operandAt($index);

            if (null === $operand) {
                break;
            }

            [$parts[], $index] = $operand;

            if ('.' !== $this->token($index)) {
                break;
            }

            ++$index;
        }

        return [] === $parts ? [null, $index] : [\implode('', $parts), $index];
    }

    /**
     * @return array{string, int}|null
     */
    private function operandAt(int $index): ?array
    {
        $token = $this->token($index);

        if (\is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $literal = \ltrim($token[1], 'bB');
            $body = \substr($literal, 1, -1);

            return [\str_starts_with($literal, '"') ? \stripcslashes($body) : $body, $index + 1];
        }

        if (\is_string($token) && '"' === \ltrim($token, 'bB')) {
            return $this->encapsedUntil($index + 1, '"', true);
        }

        if (\is_array($token) && T_START_HEREDOC === $token[0]) {
            return $this->encapsedUntil($index + 1, T_END_HEREDOC, !\str_contains($token[1], "'"));
        }

        return $this->localConstantAt($index);
    }

    /**
     * Escape sequences are decoded for a double-quoted string and a heredoc, never for a nowdoc, where a
     * backslash is data.
     *
     * @return array{string, int}
     */
    private function encapsedUntil(int $index, int|string $terminator, bool $decode): array
    {
        $text = '';

        for ($token = $this->token($index); null !== $token; $token = $this->token(++$index)) {
            if ($token === $terminator || (\is_array($token) && $token[0] === $terminator)) {
                return [$text, $index + 1];
            }

            if (!\is_array($token) || T_ENCAPSED_AND_WHITESPACE !== $token[0]) {
                $text .= self::GAP;

                continue;
            }

            $text .= $decode ? \stripcslashes($token[1]) : $token[1];
        }

        return [$text, $index];
    }

    /**
     * @return array{string, int}|null
     */
    private function localConstantAt(int $index): ?array
    {
        $scope = $this->token($index);
        $separator = $this->token($index + 1);
        $name = $this->token($index + 2);

        if (!\is_array($scope) || !\in_array(\strtolower($scope[1]), ['self', 'static'], true)) {
            return null;
        }

        if (!\is_array($separator) || T_DOUBLE_COLON !== $separator[0] || !\is_array($name) || T_STRING !== $name[0]) {
            return null;
        }

        if ('(' === $this->token($index + 3) || !\array_key_exists($name[1], $this->constants)) {
            return null;
        }

        return [$this->constants[$name[1]], $index + 3];
    }

    private function resolveConstants(): void
    {
        $count = $this->count();

        for ($index = 0; $index < $count; ++$index) {
            $token = $this->token($index);

            if (\is_array($token) && T_CONST === $token[0]) {
                $index = $this->resolveDeclarationFrom($index + 1);
            }
        }
    }

    /**
     * Reads one `const [type] A = <expr>[, B = <expr>];` statement from just past its `const` keyword and
     * returns the index of its `;`. The name is the last identifier before each `=`, which skips a type.
     */
    private function resolveDeclarationFrom(int $index): int
    {
        $name = null;

        for ($token = $this->token($index); null !== $token && ';' !== $token; $token = $this->token(++$index)) {
            if (\is_array($token) && T_STRING === $token[0]) {
                $name = $token[1];

                continue;
            }

            if ('=' === $token && null !== $name) {
                [$value, $next] = $this->at($index + 1);

                if (null !== $value && \in_array($this->token($next), [';', ','], true)) {
                    $this->constants[$name] = $value;
                }

                $name = null;
            }
        }

        return $index;
    }
}
