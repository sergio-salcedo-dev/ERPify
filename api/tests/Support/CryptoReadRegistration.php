<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use RuntimeException;

/**
 * Whether a reader registered in `CryptoReadBoundaryGateTest::READERS` can hold the contract it stands for: a
 * functional test that holds both outcome types as string literals, and a line of the error-contract page that
 * links the file as a whole path and names the outcome. Judged on the files, never on what the test asserts.
 *
 * @internal test support
 */
final class CryptoReadRegistration
{
    public const string CONTRACT = 'docs/api-error-contract.md';

    /**
     * The two outcomes a read path must pin, spelled as the wire `type` they answer with.
     *
     * @var list<string>
     */
    public const array OUTCOME_TYPES = ['dek-destroyed', 'decryption-failed'];

    /**
     * Why each registered reader cannot hold the contract it stands for; empty when every one can.
     *
     * @param array<string, array{test: string}> $readers
     *
     * @return list<string>
     */
    public static function problems(array $readers, string $repository): array
    {
        $problems = [];

        foreach ($readers as $reader => ['test' => $test]) {
            $problem = self::registrationProblem($reader, $test, $repository);

            if (null !== $problem) {
                $problems[] = $reader . ': ' . $problem . '. ' . CryptoReadBoundaryRules::RECIPE;
            }
        }

        return $problems;
    }

    private static function registrationProblem(string $reader, string $test, string $repository): ?string
    {
        return self::readerProblem($reader, self::directory($repository . '/api/src'))
            ?? self::testProblem($test, self::directory($repository . '/api/tests'))
            ?? self::contractProblem($reader, $repository . '/' . self::CONTRACT);
    }

    private static function readerProblem(string $reader, string $srcRoot): ?string
    {
        $path = \realpath($srcRoot . '/' . $reader);

        if (false === $path || !\is_file($path) || !\str_starts_with($path, $srcRoot . '/')) {
            return \sprintf('its file %s does not exist under api/src', $reader);
        }

        return null;
    }

    private static function testProblem(string $test, string $testsRoot): ?string
    {
        $path = \realpath($testsRoot . '/' . $test);

        if (false === $path || !\is_file($path)) {
            return \sprintf('its test %s does not exist', $test);
        }

        if (!\str_starts_with($path, $testsRoot . '/Functional/') || !\str_ends_with($path, 'Test.php')) {
            return \sprintf('its test %s is not a *Test.php file under api/tests/Functional', $test);
        }

        $literals = self::stringLiteralsIn((string) \file_get_contents($path));

        foreach (self::OUTCOME_TYPES as $type) {
            if (!self::anyContains($literals, $type)) {
                return \sprintf('its test %s does not assert %s', $test, $type);
            }
        }

        return null;
    }

    private static function contractProblem(string $reader, string $contract): ?string
    {
        if (!\is_file($contract)) {
            throw new RuntimeException($contract . ' does not exist');
        }

        if (self::contractStatesTheReader((string) \file_get_contents($contract), $reader)) {
            return null;
        }

        return \sprintf(
            '%s has no line that links %s and names %s',
            self::CONTRACT,
            $reader,
            self::OUTCOME_TYPES[0],
        );
    }

    /**
     * The text of every string literal in a PHP source, so a type named only in a comment does not count.
     *
     * @return list<string>
     */
    private static function stringLiteralsIn(string $source): array
    {
        $literals = [];

        foreach (\token_get_all($source) as $token) {
            $isLiteral = \is_array($token)
                && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);

            if ($isLiteral) {
                $literals[] = $token[1];
            }
        }

        return $literals;
    }

    /**
     * @param list<string> $haystacks
     */
    private static function anyContains(array $haystacks, string $needle): bool
    {
        return \array_any($haystacks, static fn (string $haystack): bool => \str_contains($haystack, $needle));
    }

    /**
     * A line of the page that holds the reader's path as a whole path (not a prefix of a longer one) and names the
     * outcome that path owes a deliberate status. A file the page already cites for another reason does not pass
     * unless that line also says what the read path answers.
     */
    private static function contractStatesTheReader(string $contract, string $reader): bool
    {
        $path = '#api/src/' . \preg_quote($reader, '#') . '(?![\w\-]|\.\w)#';

        return \array_any(
            \explode("\n", $contract),
            static fn ($line): bool => \str_contains($line, self::OUTCOME_TYPES[0]) && 1 === \preg_match($path, $line),
        );
    }

    private static function directory(string $path): string
    {
        $real = \realpath($path);

        if (false === $real || !\is_dir($real)) {
            throw new RuntimeException($path . ' does not exist');
        }

        return $real;
    }
}
