<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * A PHP source as its significant tokens — whitespace, comments and docblocks dropped — so a sweep reading it
 * sees code and never the prose around it, with the few positional questions a call-shape sweep asks of them.
 *
 * @internal test support
 */
final readonly class SignificantPhpTokens
{
    /**
     * @var list<array{int, string, int}|string>
     */
    private array $tokens;

    public function __construct(string $source)
    {
        $this->tokens = \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * @return list<int>
     */
    public function indices(): array
    {
        return \array_keys($this->tokens);
    }

    public function idAt(int $index): ?int
    {
        $token = $this->tokens[$index] ?? null;

        return \is_array($token) ? $token[0] : null;
    }

    public function textAt(int $index): string
    {
        $token = $this->tokens[$index] ?? '';

        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * Whether `$index` is the `->` (or `?->`) of a method call: an operator, a name, then `(`.
     */
    public function isMethodCallAt(int $index): bool
    {
        return \in_array($this->idAt($index), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && T_STRING === $this->idAt($index + 1)
            && '(' === $this->textAt($index + 2);
    }

    /**
     * Whether the name at `$index` spells `$class`, bare or qualified.
     */
    public function namesClassAt(int $index, string $class): bool
    {
        $name = \ltrim($this->textAt($index), '\\');

        return $class === $name || \str_ends_with($name, '\\' . $class);
    }

    /**
     * The index of the `)` closing the `(` at `$open`, or the token count when the source never closes it.
     */
    public function closingParenthesisOf(int $open): int
    {
        $depth = 0;
        $count = \count($this->tokens);

        for ($index = $open; $index < $count; ++$index) {
            $depth += match ($this->textAt($index)) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if (0 === $depth) {
                return $index;
            }
        }

        return $count;
    }

    /**
     * The index of the nearest `function` keyword at or before `$index` that declares a NAMED function — a
     * closure's is followed by `(`, so it is passed over — or 0 when there is none.
     */
    public function enclosingFunctionAt(int $index): int
    {
        for ($cursor = $index; $cursor > 0; --$cursor) {
            if (T_FUNCTION === $this->idAt($cursor) && T_STRING === $this->idAt($cursor + 1)) {
                return $cursor;
            }
        }

        return 0;
    }
}
