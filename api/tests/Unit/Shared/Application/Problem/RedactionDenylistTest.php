<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\RedactionDenylist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 */
#[CoversClass(RedactionDenylist::class)]
final class RedactionDenylistTest extends TestCase
{
    public function testKeysConstantContainsTheSevenCanonicalDenylistKeys(): void
    {
        // @phpstan-ignore method.alreadyNarrowedType (the assertion IS the test — pins the constant baseline at runtime)
        $this->assertSame(
            RedactionDenylist::KEYS,
            ['password', 'token', 'secret', 'authorization', 'cookie', 'ssn', 'iban'],
        );
    }

    public function testKeysAreAllLowercaseAscii(): void
    {
        foreach (RedactionDenylist::KEYS as $key) {
            $this->assertSame($key, \strtolower($key), \sprintf('Key "%s" must be lowercase.', $key));
            $this->assertSame(1, \preg_match('/\A[a-z][a-z0-9_-]*\z/', $key), \sprintf('Key "%s" must be ASCII identifier-shaped.', $key));
        }
    }

    /**
     * @param non-empty-string $caseVariant
     */
    #[DataProvider('provideFilterStripsExactKeyMatchCaseInsensitiveCases')]
    public function testFilterStripsExactKeyMatchCaseInsensitive(string $caseVariant): void
    {
        $filtered = RedactionDenylist::filter([
            $caseVariant => 'sensitive',
            'safe' => 'kept',
        ]);

        $this->assertArrayNotHasKey($caseVariant, $filtered);
        $this->assertArrayHasKey('safe', $filtered);
        $this->assertSame('kept', $filtered['safe']);
    }

    /**
     * @return iterable<string, array{0: non-empty-string}>
     */
    public static function provideFilterStripsExactKeyMatchCaseInsensitiveCases(): iterable
    {
        foreach (RedactionDenylist::KEYS as $canonical) {
            yield $canonical . ' (lower)' => [$canonical];
            yield $canonical . ' (Title)' => [\ucfirst($canonical)];
            yield $canonical . ' (UPPER)' => [\strtoupper($canonical)];
            yield $canonical . ' (mIxEd)' => [self::alternatingCase($canonical)];
        }
    }

    /**
     * @param non-empty-string $key
     */
    #[DataProvider('provideFilterDoesNotStripSubstringPrefixOrSuffixCases')]
    public function testFilterDoesNotStripSubstringPrefixOrSuffix(string $key): void
    {
        $filtered = RedactionDenylist::filter([$key => 'kept']);

        $this->assertArrayHasKey($key, $filtered);
        $this->assertSame('kept', $filtered[$key]);
    }

    /**
     * @return iterable<string, array{0: non-empty-string}>
     */
    public static function provideFilterDoesNotStripSubstringPrefixOrSuffixCases(): iterable
    {
        yield 'substring (my_password)' => ['my_password'];
        yield 'substring/prefix (password_hash)' => ['password_hash'];
        yield 'prefix-only (pass)' => ['pass'];
        yield 'leading whitespace ( password)' => [' password'];
        yield 'trailing whitespace (password )' => ['password '];
        yield 'non-ASCII (PÄSSWORD)' => ['PÄSSWORD'];
        yield 'unrelated (email)' => ['email'];
        yield 'numeric-string (42)' => ['42'];
    }

    public function testFilterIsSingleLevelAndDoesNotRecurse(): void
    {
        $input = ['user' => ['password' => 'x', 'name' => 'alice']];

        $this->assertSame($input, RedactionDenylist::filter($input));
    }

    public function testFilterPreservesNumericKeys(): void
    {
        $this->assertSame([0 => 'a', 1 => 'b'], RedactionDenylist::filter([0 => 'a', 1 => 'b']));
    }

    public function testFilterReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], RedactionDenylist::filter([]));
    }

    public function testFilterPreservesDeclarationOrderOfSurvivingKeys(): void
    {
        $filtered = RedactionDenylist::filter([
            'a' => 1,
            'password' => 'x',
            'b' => 2,
            'token' => 'y',
            'c' => 3,
        ]);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $filtered);
    }

    public function testFilterReturnsEmptyArrayWhenAllInputKeysAreDenylisted(): void
    {
        $this->assertSame([], RedactionDenylist::filter([
            'password' => 'x',
            'TOKEN' => 'y',
            'Secret' => 'z',
        ]));
    }

    public function testFilterPreservesValueIdentityForSurvivingKeys(): void
    {
        $object = new stdClass();

        $filtered = RedactionDenylist::filter(['x' => $object]);

        $this->assertArrayHasKey('x', $filtered);
        $this->assertSame($object, $filtered['x']);
    }

    public function testClassIsFinalAndUtilityShaped(): void
    {
        $reflectionClass = new ReflectionClass(RedactionDenylist::class);

        $this->assertTrue($reflectionClass->isFinal(), 'RedactionDenylist must be final (enums are implicitly final).');
        $userDefinedPublicMethods = \array_values(\array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            \array_filter(
                $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method->isUserDefined(),
            ),
        ));
        $this->assertSame(
            ['filter'],
            $userDefinedPublicMethods,
            'RedactionDenylist must declare only the static `filter` public method (the implicit enum `cases()` is engine-provided and exempt).',
        );
        $this->assertCount(1, $reflectionClass->getReflectionConstants(), 'RedactionDenylist must declare exactly one constant (KEYS).');
    }

    public function testCannotInstantiate(): void
    {
        $reflectionClass = new ReflectionClass(RedactionDenylist::class);

        $this->assertTrue(
            $reflectionClass->isEnum(),
            'RedactionDenylist must be an enum — instantiation is forbidden by the PHP engine for this static-only utility (anti-pattern: "Do NOT instantiate RedactionDenylist as a service").',
        );
        $this->assertNotInstanceOf(
            ReflectionMethod::class,
            $reflectionClass->getConstructor(),
            'Enums cannot declare a constructor; this assertion documents the PHP-level guarantee.',
        );
    }

    public function testDataProviderRowCountMatchesKeysCountTimesFour(): void
    {
        $rows = \iterator_to_array(self::provideFilterStripsExactKeyMatchCaseInsensitiveCases(), preserve_keys: false);

        $this->assertCount(
            \count(RedactionDenylist::KEYS) * 4,
            $rows,
            'Adding a new key to RedactionDenylist::KEYS requires adding four casing rows to provideFilterStripsExactKeyMatchCaseInsensitiveCases.',
        );
    }

    /**
     * @param non-empty-string $value
     *
     * @return non-empty-string
     */
    private static function alternatingCase(string $value): string
    {
        $characters = \str_split($value);

        $alternated = \array_map(
            static fn (string $character, int $index): string => 0 === $index % 2 ? $character : \strtoupper($character),
            $characters,
            \array_keys($characters),
        );

        return $value[0] . \substr(\implode('', $alternated), 1);
    }
}
