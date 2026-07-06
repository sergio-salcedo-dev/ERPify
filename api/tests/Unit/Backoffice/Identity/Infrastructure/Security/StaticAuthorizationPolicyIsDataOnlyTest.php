<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tripwire for "policy = data, not mechanism": each policy map must be a literal, so authorization decisions
 * stay a data lookup and never grow a hand-written branch. Declaring the maps as `const` already lets the
 * compiler forbid closures and calls; this test adds a cheap second line — it tokenises the value of each
 * const and fails if any control-flow, closure, call, ternary, `new` or nullsafe token appears there.
 * Enum-case and class-constant fetches (`Role::VIEWER->value`, `self::WILDCARD`) are pure data and pass; a
 * `match`, `fn`, function call, `?:`, `new Foo` or `?->` slipped into the map would not. The heavier CI gate
 * (core-set invariance, `subject:` left unread) belongs to a later story; this one is the local, fast tripwire.
 *
 * `nikic/php-parser` is not in the app autoload, so this reads the source with {@see \token_get_all()}.
 *
 * @internal
 */
#[CoversClass(StaticAuthorizationPolicy::class)]
final class StaticAuthorizationPolicyIsDataOnlyTest extends TestCase
{
    /**
     * Single-character tokens that only appear when a value is computed rather than declared: `(` opens any
     * call / closure / `match` / `new(...)`, `?` opens a ternary.
     */
    private const array FORBIDDEN_CHAR_TOKENS = ['(', '?'];

    /**
     * Keyword/operator tokens that would betray executable logic inside the map. `(`-based constructs are
     * already caught by {@see self::FORBIDDEN_CHAR_TOKENS}; these are the parenthesis-free ones.
     *
     * @var list<int>
     */
    private const array FORBIDDEN_KEYWORD_TOKENS = [
        T_FN,
        T_FUNCTION,
        T_MATCH,
        T_NEW,
        T_COALESCE,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_IF,
        T_SWITCH,
        T_FOR,
        T_FOREACH,
        T_WHILE,
    ];

    #[DataProvider('provideThePolicyDataConstantIsPureDataCases')]
    public function testThePolicyDataConstantIsPureData(string $constantName): void
    {
        $valueTokens = $this->valueTokensOfConstant($constantName);

        $this->assertNotEmpty($valueTokens, \sprintf('Constant %s was not found in the policy source.', $constantName));

        foreach ($valueTokens as $valueToken) {
            $this->assertFalse($this->isExecutable($valueToken), \sprintf(
                'Policy data constant %s must be literal data, but found an executable token: %s',
                $constantName,
                $this->describe($valueToken),
            ));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideThePolicyDataConstantIsPureDataCases(): iterable
    {
        yield 'TIER_VERBS' => ['TIER_VERBS'];
        yield 'EXPLICIT_GRANTS' => ['EXPLICIT_GRANTS'];
        yield 'TIER_OPT_OUT' => ['TIER_OPT_OUT'];
    }

    /**
     * The tokens of the given constant's value expression — everything between its `=` and the terminating
     * `;` at bracket depth zero.
     *
     * @return list<array{int, string, int}|string>
     */
    private function valueTokensOfConstant(string $constantName): array
    {
        return $this->tokensUntilStatementEnd($this->tokensAfterAssignmentOf($constantName));
    }

    /**
     * Every token that follows the `=` of the named constant, up to end of file. A tiny state machine kept
     * flat (predicates, not nested conditionals) so it stays under the complexity budget: it arms on the
     * `const` keyword, disarms on `;` (so `self::TIER_VERBS` used elsewhere as a default never matches), and
     * starts collecting once it sees the named constant's `=`.
     *
     * @return list<array{int, string, int}|string>
     */
    private function tokensAfterAssignmentOf(string $constantName): array
    {
        $rest = [];
        $collecting = false;
        $atConstant = false;
        $sawConst = false;

        foreach ($this->policySourceTokens() as $token) {
            if ($collecting) {
                $rest[] = $token;
            } elseif ($this->isConstKeyword($token)) {
                $sawConst = true;
            } elseif (';' === $token) {
                $sawConst = false;
                $atConstant = false;
            } elseif ($sawConst && $this->isNamedString($token, $constantName)) {
                $atConstant = true;
            } elseif ($atConstant && '=' === $token) {
                $collecting = true;
            }
        }

        return $rest;
    }

    /**
     * The prefix of $tokens up to (excluding) the first `;` at array-bracket depth zero — the constant's
     * value expression. Policy maps are pure array literals, so only `[`/`]` move the depth.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private function tokensUntilStatementEnd(array $tokens): array
    {
        $captured = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ('[' === $token) {
                ++$depth;
            } elseif (']' === $token) {
                --$depth;
            } elseif (';' === $token && 0 === $depth) {
                break;
            }

            $captured[] = $token;
        }

        return $captured;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isConstKeyword(array|string $token): bool
    {
        return \is_array($token) && T_CONST === $token[0];
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isNamedString(array|string $token, string $name): bool
    {
        return \is_array($token) && T_STRING === $token[0] && $name === $token[1];
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private function policySourceTokens(): array
    {
        $file = (new ReflectionClass(StaticAuthorizationPolicy::class))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        return \token_get_all($source);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isExecutable(array|string $token): bool
    {
        if (\is_string($token)) {
            return \in_array($token, self::FORBIDDEN_CHAR_TOKENS, true);
        }

        return \in_array($token[0], self::FORBIDDEN_KEYWORD_TOKENS, true);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function describe(array|string $token): string
    {
        return \is_string($token) ? $token : \token_name($token[0]);
    }
}
