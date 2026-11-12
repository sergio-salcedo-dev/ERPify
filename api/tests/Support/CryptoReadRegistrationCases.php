<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The planted registrations `CryptoReadBoundaryRulesGateTest` judges: each case is the complete planted repository
 * with one file laid over it, so exactly one thing is wrong (or, for the accepted ones, nothing).
 *
 * @internal test support
 */
final class CryptoReadRegistrationCases
{
    private const string READER = 'Documents/Download.php';

    private const string PHP_TEST = 'tests/Functional/Documents/DownloadTest.php';

    private const string FEATURE = 'features/documents/read.feature';

    private const string CONTRACT_PAGE = 'docs/api-error-contract.md';

    private const string NOT_A_TEST = 'is not a *Test.php under api/tests/Functional or a .feature under api/features';

    private const string ABSENT = 'does not exist under api/';

    private const string UNSTATED = 'has no single line that cites api/src/Documents/Download.php '
        . 'and names dek-destroyed';

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    public static function all(): iterable
    {
        yield from self::acceptedRegistrations();
        yield from self::registrationsWithAnUnusableFile();
        yield from self::registrationsWhoseTestHoldsTooLittle();
        yield from self::registrationsWhoseContractLineIsWrong();
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    private static function acceptedRegistrations(): iterable
    {
        $gherkin = "Feature: read\n  Then the type is \"dek-destroyed\"\n  And the type is \"decryption-failed\"\n";

        yield 'both outcomes held and the contract cites the reader' => [
            self::completeRepository(),
            [self::READER => ['test' => self::PHP_TEST]],
            null,
        ];
        yield 'a feature holding both outcomes outside a comment' => self::registration(
            ['api/' . self::FEATURE => $gherkin],
            self::READER,
            self::FEATURE,
            null,
        );
        yield 'a contract line written as a link relative to docs/' => self::registration(
            [self::CONTRACT_PAGE => '| x | [x](../api/src/Documents/Download.php) | `dek-destroyed` |' . "\n"],
            self::READER,
            self::PHP_TEST,
            null,
        );
        yield 'a reader whose path holds regex metacharacters' => self::registration(
            [
                'api/src/Documents/Foo+Bar[1].php' => '<?php // reader',
                self::CONTRACT_PAGE => '| x | api/src/Documents/Foo+Bar[1].php | `dek-destroyed` |' . "\n",
            ],
            'Documents/Foo+Bar[1].php',
            self::PHP_TEST,
            null,
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    private static function registrationsWithAnUnusableFile(): iterable
    {
        $unit = ['api/tests/Unit/DownloadTest.php' => self::assertingBothTypes()];

        yield 'a reader that does not exist' => self::registration(
            [],
            'Absent.php',
            self::PHP_TEST,
            'its file Absent.php',
        );
        yield 'a reader that is a directory' => self::registration(
            [],
            'Documents',
            self::PHP_TEST,
            'its file Documents does',
        );
        yield 'a reader outside api/src' => self::registration(
            [],
            '../' . self::PHP_TEST,
            self::PHP_TEST,
            'does not exist under api/src',
        );
        yield 'a test that does not exist' => self::registration(
            [],
            self::READER,
            'tests/Functional/X.php',
            self::ABSENT,
        );
        yield 'a test that is a directory' => self::registration(
            [],
            self::READER,
            'tests/Functional/Documents',
            self::ABSENT,
        );
        yield 'a test outside Functional' => self::registration(
            $unit,
            self::READER,
            'tests/Unit/DownloadTest.php',
            self::NOT_A_TEST,
        );
        yield 'a test that escapes Functional through ..' => self::registration(
            $unit,
            self::READER,
            'tests/Functional/../Unit/DownloadTest.php',
            self::NOT_A_TEST,
        );
        yield 'a test in a sibling of Functional' => self::registration(
            ['api/tests/Functional2/DownloadTest.php' => self::assertingBothTypes()],
            self::READER,
            'tests/Functional2/DownloadTest.php',
            self::NOT_A_TEST,
        );
        yield 'a file under Functional that is not a test class file' => self::registration(
            ['api/tests/Functional/Helper.php' => self::assertingBothTypes()],
            self::READER,
            'tests/Functional/Helper.php',
            self::NOT_A_TEST,
        );
        yield 'a feature with the wrong extension' => self::registration(
            ['api/features/read.txt' => 'Feature: read'],
            self::READER,
            'features/read.txt',
            self::NOT_A_TEST,
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    private static function registrationsWhoseTestHoldsTooLittle(): iterable
    {
        $php = 'api/' . self::PHP_TEST;
        $literal = 'as a string literal';

        yield 'a test that holds only dek-destroyed' => self::registration(
            [$php => "<?php return ['dek-destroyed'];"],
            self::READER,
            self::PHP_TEST,
            'does not hold decryption-failed ' . $literal,
        );
        yield 'a test that holds only decryption-failed' => self::registration(
            [$php => "<?php return ['decryption-failed'];"],
            self::READER,
            self::PHP_TEST,
            'does not hold dek-destroyed ' . $literal,
        );
        yield 'a test that names both types only in a comment' => self::registration(
            [$php => "<?php // 'dek-destroyed' 'decryption-failed'"],
            self::READER,
            self::PHP_TEST,
            'does not hold dek-destroyed ' . $literal,
        );
        yield 'a feature that names both types only in a comment' => self::registration(
            ['api/' . self::FEATURE => "# dek-destroyed decryption-failed\nFeature: read\n"],
            self::READER,
            self::FEATURE,
            'does not hold dek-destroyed as text outside a comment',
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    private static function registrationsWhoseContractLineIsWrong(): iterable
    {
        $wrong = static fn (string $page): array => self::registration(
            [self::CONTRACT_PAGE => $page],
            self::READER,
            self::PHP_TEST,
            self::UNSTATED,
        );

        yield 'a contract that does not mention the reader' => $wrong("# Errors\n");
        yield 'a contract line that cites the reader without the outcome' => $wrong(
            "| x | api/src/Documents/Download.php |\n",
        );
        yield 'a contract that cites a longer path' => $wrong(
            "| x | api/src/Documents/Download.php.bak | dek-destroyed |\n",
        );
        yield 'a contract that cites the reader as the tail of another path' => $wrong(
            '| x | old-api/src/Documents/Download.php | `dek-destroyed` |' . "\n",
        );
        yield 'a contract that cites the reader and the outcome on different lines' => $wrong(
            "api/src/Documents/Download.php\n`dek-destroyed`\n",
        );
    }

    /**
     * One registration: the complete planted repository with `$files` laid over it.
     *
     * @param array<string, string> $files
     *
     * @return array{array<string, string>, array<string, array{test: string}>, ?string}
     */
    private static function registration(array $files, string $reader, string $test, ?string $problem): array
    {
        return [[...self::completeRepository(), ...$files], [$reader => ['test' => $test]], $problem];
    }

    /**
     * A planted repository carrying the reader, a passing functional test and a contract row, for the cases that
     * each break exactly one of them.
     *
     * @return array<string, string>
     */
    public static function completeRepository(): array
    {
        return [
            'api/src/Documents/Download.php' => '<?php // reader',
            'api/tests/Functional/Documents/DownloadTest.php' => self::assertingBothTypes(),
            'docs/api-error-contract.md' => '| download | api/src/Documents/Download.php | `dek-destroyed` |' . "\n",
        ];
    }

    public static function assertingBothTypes(): string
    {
        return "<?php return ['dek-destroyed', 'decryption-failed'];";
    }
}
