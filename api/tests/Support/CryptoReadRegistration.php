<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\DekDestroyed;
use RuntimeException;

/**
 * Whether a reader registered in `CryptoReadBoundaryGateTest::READERS` can hold the contract it stands for: a
 * test that holds both crypto outcome types, and a line of the error-contract page that cites the file as a
 * whole path and names the first outcome. Judged on the files, never on what the test does.
 *
 * Two kinds of test are accepted, because the repository proves an HTTP route in two lanes: a PHPUnit
 * `*Test.php` under `api/tests/Functional` (the type held as a string literal, so a comment never counts) and a
 * Behat `.feature` under `api/features` (the type held outside a `#` comment line).
 *
 * @internal test support
 */
final class CryptoReadRegistration
{
    /**
     * The two crypto CAUSES a read path must provoke and pin, spelled as the `type` those exceptions carry. They
     * are the causes, not necessarily the type on the wire after the read boundary translates them.
     *
     * @var list<string>
     */
    public const array OUTCOME_TYPES = [DekDestroyed::TYPE, DecryptionFailed::TYPE];

    public const string CONTRACT = 'docs/api-error-contract.md';

    private const string TEST_KINDS = 'a *Test.php under api/tests/Functional or a .feature under api/features';

    /**
     * Why each registered reader cannot hold the contract it stands for; empty when every one can.
     *
     * @param array<string, array{test: string}> $readers reader (relative to api/src) => ['test' => path from api/]
     *
     * @return list<string>
     */
    public static function problems(array $readers, string $repository): array
    {
        $problems = [];

        foreach ($readers as $reader => ['test' => $test]) {
            $problem = self::problem($reader, $test, $repository);

            if (null !== $problem) {
                $problems[] = $reader . ': ' . $problem . '. ' . CryptoReadBoundaryRules::RECIPE;
            }
        }

        return $problems;
    }

    private static function problem(string $reader, string $test, string $repository): ?string
    {
        $apiRoot = self::directory($repository . '/api');

        return self::readerProblem($reader, $apiRoot . '/src')
            ?? self::testProblem($test, $apiRoot)
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

    private static function testProblem(string $test, string $apiRoot): ?string
    {
        $path = \realpath($apiRoot . '/' . $test);

        if (false === $path || !\is_file($path)) {
            return \sprintf('its test %s does not exist under api/', $test);
        }

        $kind = self::kindOf($path, $apiRoot);

        if (null === $kind) {
            return \sprintf('its test %s is not %s', $test, self::TEST_KINDS);
        }

        $texts = 'php' === $kind
            ? self::stringLiteralsIn(self::read($path))
            : self::featureLinesIn(self::read($path));

        $form = 'php' === $kind ? 'a string literal' : 'text outside a comment';

        foreach (self::OUTCOME_TYPES as $type) {
            if (!self::anyContains($texts, $type)) {
                return \sprintf('its test %s does not hold %s as %s', $test, $type, $form);
            }
        }

        return null;
    }

    private static function contractProblem(string $reader, string $contract): ?string
    {
        if (self::contractNamesTheReader(self::read($contract), $reader)) {
            return null;
        }

        return \sprintf(
            '%s has no single line that cites api/src/%s and names %s',
            self::CONTRACT,
            $reader,
            self::OUTCOME_TYPES[0],
        );
    }

    /**
     * @return 'php'|'feature'|null
     */
    private static function kindOf(string $path, string $apiRoot): ?string
    {
        if (\str_starts_with($path, $apiRoot . '/tests/Functional/') && \str_ends_with($path, 'Test.php')) {
            return 'php';
        }

        if (\str_starts_with($path, $apiRoot . '/features/') && \str_ends_with($path, '.feature')) {
            return 'feature';
        }

        return null;
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
     * The lines of a Gherkin source that are not `#` comments.
     *
     * @return list<string>
     */
    private static function featureLinesIn(string $source): array
    {
        return \array_values(\array_filter(
            \explode("\n", $source),
            static fn (string $line): bool => !\str_starts_with(\ltrim($line), '#'),
        ));
    }

    /**
     * @param list<string> $haystacks
     */
    private static function anyContains(array $haystacks, string $needle): bool
    {
        return \array_any($haystacks, static fn (string $haystack): bool => \str_contains($haystack, $needle));
    }

    /**
     * A line of the page that cites the reader's path as a whole path (not as the tail of a longer one or the
     * head of another) and names the outcome. A file the page already cites for another reason does not pass
     * unless that same line also says what the read path answers. A citation is not a Markdown link: prose
     * satisfies it, and whether the line says the right thing is review's to judge.
     */
    private static function contractNamesTheReader(string $contract, string $reader): bool
    {
        $path = '#(?<![\w\-])api/src/' . \preg_quote($reader, '#') . '(?![\w\-]|\.\w)#';

        return \array_any(
            \explode("\n", $contract),
            static fn (string $line): bool => \str_contains($line, self::OUTCOME_TYPES[0])
                && 1 === \preg_match($path, $line),
        );
    }

    private static function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if (!\is_string($contents)) {
            throw new RuntimeException($path . ' does not exist or could not be read');
        }

        return $contents;
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
