<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CryptoReadBoundaryRules;
use Erpify\Tests\Support\CryptoReadRegistration;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Falsifiability of the rules `CryptoReadBoundaryGateTest` asserts over a tree that holds no reader yet: each
 * check is driven with planted input, so the first real registration runs code that has already failed here.
 * The engine is `Tests\Support\CryptoReadBoundaryRules` (detection) and `Tests\Support\CryptoReadRegistration`.
 *
 * @internal
 */
#[CoversNothing]
final class CryptoReadBoundaryRulesGateTest extends TestCase
{
    private const string PLANTED_READER = 'Documents/Document/Application/Download/Planted.php';

    /** @var list<string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $root) {
            $this->removeTree($root);
        }

        $this->fixtures = [];
    }

    /**
     * @param list<string> $reading
     * @param list<string> $registered
     * @param list<string> $needles    every one must appear in the single problem reported
     */
    #[DataProvider('provideAReaderMismatchIsReportedInBothDirectionsCases')]
    #[Test]
    public function aReaderMismatchIsReportedInBothDirections(array $reading, array $registered, array $needles): void
    {
        $problems = CryptoReadBoundaryRules::readerProblems($reading, $registered);

        $this->assertCount(1, $problems);

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $problems[0]);
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function provideAReaderMismatchIsReportedInBothDirectionsCases(): iterable
    {
        yield 'a file reads and is not registered' => [
            [self::PLANTED_READER],
            [],
            [self::PLANTED_READER, CryptoReadBoundaryRules::RECIPE],
        ];
        yield 'a registered file no longer reads' => [
            [],
            [self::PLANTED_READER],
            [self::PLANTED_READER, 'no longer reads'],
        ];
    }

    #[Test]
    public function aMethodANewPortGrowsIsARead(): void
    {
        $port = new class {
            public function encrypt(): void
            {
            }

            public function open(): void
            {
            }

            protected function internal(): void
            {
            }
        };

        $this->assertSame(['open'], CryptoReadBoundaryRules::readMethodsOf([$port]));
    }

    /**
     * @param array<string, string>              $files   repository-relative path => contents of the planted repository
     * @param array<string, array{test: string}> $readers
     */
    #[DataProvider('provideARegistrationIsJudgedByWhatItCanHoldCases')]
    #[Test]
    public function aRegistrationIsJudgedByWhatItCanHold(array $files, array $readers, ?string $problem): void
    {
        $problems = CryptoReadRegistration::problems($readers, $this->plantedRepository($files));

        if (null === $problem) {
            $this->assertSame([], $problems);

            return;
        }

        $this->assertCount(1, $problems);
        $this->assertStringContainsString($problem, $problems[0]);
        $this->assertStringContainsString(CryptoReadBoundaryRules::RECIPE, $problems[0]);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, array{test: string}>, ?string}>
     */
    public static function provideARegistrationIsJudgedByWhatItCanHoldCases(): iterable
    {
        $reader = 'Documents/Download.php';
        $test = 'Functional/Documents/DownloadTest.php';
        $testFile = 'api/tests/' . $test;
        $notATest = 'is not a *Test.php file under api/tests/Functional';
        $contract = 'docs/api-error-contract.md';
        $unstated = 'has no line that links Documents/Download.php and names dek-destroyed';

        yield 'both outcomes pinned and the contract links the reader' => [
            self::completeRepository(),
            [$reader => ['test' => $test]],
            null,
        ];

        yield 'reader that does not exist' => self::refused([], 'Absent.php', $test, 'its file Absent.php');
        yield 'reader that is a directory' => self::refused([], 'Documents', $test, 'its file Documents does');
        yield 'reader outside api/src' => self::refused([], '../tests/' . $test, $test, 'does not exist under api/src');
        yield 'test that does not exist' => self::refused([], $reader, 'Functional/X.php', 'its test Functional/X.php');
        yield 'test that is a directory' => self::refused([], $reader, 'Functional/Documents', 'its test Functional/D');
        yield 'test outside Functional' => self::refused(
            ['api/tests/Unit/DownloadTest.php' => self::assertingBothTypes()],
            $reader,
            'Unit/DownloadTest.php',
            $notATest,
        );
        yield 'file under Functional that is not a test class file' => self::refused(
            ['api/tests/Functional/Helper.php' => self::assertingBothTypes()],
            $reader,
            'Functional/Helper.php',
            $notATest,
        );
        yield 'test that pins only dek-destroyed' => self::refused(
            [$testFile => "<?php return ['dek-destroyed'];"],
            $reader,
            $test,
            'does not assert decryption-failed',
        );
        yield 'test that pins only decryption-failed' => self::refused(
            [$testFile => "<?php return ['decryption-failed'];"],
            $reader,
            $test,
            'does not assert dek-destroyed',
        );
        yield 'test that names both types only in a comment' => self::refused(
            [$testFile => "<?php // 'dek-destroyed' 'decryption-failed'"],
            $reader,
            $test,
            'does not assert dek-destroyed',
        );
        yield 'contract that does not mention the reader' => self::refused(
            [$contract => "# Errors\n"],
            $reader,
            $test,
            $unstated,
        );
        yield 'contract that links the reader on a line that does not name the outcome' => self::refused(
            [$contract => "| download | api/src/Documents/Download.php |\n"],
            $reader,
            $test,
            $unstated,
        );
        yield 'contract that links only a longer path' => self::refused(
            [$contract => "| download | api/src/Documents/Download.php.bak | dek-destroyed |\n"],
            $reader,
            $test,
            $unstated,
        );
    }

    /**
     * One refused registration: the complete planted repository with `$files` laid over it.
     *
     * @param array<string, string> $files
     *
     * @return array{array<string, string>, array<string, array{test: string}>, string}
     */
    private static function refused(array $files, string $reader, string $test, string $problem): array
    {
        return [[...self::completeRepository(), ...$files], [$reader => ['test' => $test]], $problem];
    }

    #[Test]
    public function theSweepIgnoresTheCryptoDirectoryAndNothingBeside(): void
    {
        $source = '<?php $port->%s($scope);';
        $repository = $this->plantedRepository([
            'api/src/Shared/Crypto/Inner.php' => \sprintf($source, 'decrypt'),
            'api/src/Shared/CryptoX/Outer.php' => \sprintf($source, 'decrypt'),
            'api/src/Documents/Reader.php' => \sprintf($source, 'unseal'),
            'api/src/Documents/Writer.php' => \sprintf($source, 'encrypt'),
        ]);

        $this->assertSame(
            ['Documents/Reader.php' => ['unseal'], 'Shared/CryptoX/Outer.php' => ['decrypt']],
            CryptoReadBoundaryRules::readersIn(['decrypt', 'unseal'], $repository . '/api/src'),
        );
    }

    /**
     * @param list<string> $calls what the engine must report for `$source`, among `decrypt` and `unseal`
     */
    #[DataProvider('provideTheEngineSeesInstanceCallsAndNothingElseCases')]
    #[Test]
    public function theEngineSeesInstanceCallsAndNothingElse(string $source, array $calls): void
    {
        $this->assertSame($calls, CryptoReadBoundaryRules::callsIn('<?php ' . $source, ['decrypt', 'unseal']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideTheEngineSeesInstanceCallsAndNothingElseCases(): iterable
    {
        yield 'plain' => ['$this->encryptor->decrypt($scope, $c, $aad);', ['decrypt']];
        yield 'nullsafe' => ['$encryptor?->unseal($scope, $in, $out, $aad);', ['unseal']];
        yield 'space before the parenthesis' => ['$e->decrypt ($scope);', ['decrypt']];
        yield 'chain broken over lines' => ["\$this->encryptor\n    ->\n    decrypt(\$scope);", ['decrypt']];
        yield 'comment inside the chain' => ['$e->/* why */decrypt($scope);', ['decrypt']];
        yield 'method names are case-insensitive' => ['$e->Decrypt($scope);', ['decrypt']];
        yield 'first-class callable' => ['$f = $e->unseal(...);', ['unseal']];
        yield 'both ports in one body' => ['$a->decrypt($x); $b->unseal($y);', ['decrypt', 'unseal']];
        yield 'declaration' => ['public function decrypt(string $c): string { return $c; }', []];
        yield 'string literal' => ["\$s = '->decrypt(';", []];
        yield 'comment' => ['// $e->decrypt($x)', []];
        yield 'docblock' => ['/** @see $e->unseal() */', []];
        yield 'longer name' => ['$e->decrypted($x);', []];
        yield 'property of the same name' => ['$x = $e->decrypt;', []];
        yield 'a write' => ['$e->encrypt($scope, $p, $aad); $e->seal($s, $in, $out, $aad);', []];
        yield 'a vendor function ending in the name' => ['\sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($c);', []];
        // Pinned blind spot: a static receiver is not an instance call, and the ports are injected instances.
        yield 'static call' => ['Port::decrypt($scope);', []];
    }

    /**
     * A planted repository carrying the reader, a passing functional test and a contract row, for the cases that
     * each break exactly one of them.
     *
     * @return array<string, string>
     */
    private static function completeRepository(): array
    {
        return [
            'api/src/Documents/Download.php' => '<?php // reader',
            'api/tests/Functional/Documents/DownloadTest.php' => self::assertingBothTypes(),
            'docs/api-error-contract.md' => '| download | api/src/Documents/Download.php | `dek-destroyed` |' . "\n",
        ];
    }

    private static function assertingBothTypes(): string
    {
        return "<?php return ['dek-destroyed', 'decryption-failed'];";
    }

    /**
     * @param array<string, string> $files
     */
    private function plantedRepository(array $files): string
    {
        $root = \sys_get_temp_dir() . '/crypto-read-gate-' . \bin2hex(\random_bytes(6));
        $this->fixtures[] = $root;

        // The roots must exist for a case that plants only one side of the repository.
        foreach (['api/src', 'api/tests/Functional'] as $required) {
            if (!\mkdir($root . '/' . $required, 0o777, true)) {
                $this->fail('could not create ' . $root . '/' . $required);
            }
        }

        foreach ($files as $path => $contents) {
            $target = $root . '/' . $path;
            $directory = \dirname($target);

            if (!\is_dir($directory) && !\mkdir($directory, 0o777, true) && !\is_dir($directory)) {
                $this->fail('could not create ' . $directory);
            }

            \file_put_contents($target, $contents);
        }

        return $root;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir($root);
    }
}
