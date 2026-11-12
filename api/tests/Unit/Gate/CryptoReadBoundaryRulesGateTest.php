<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CryptoReadBoundaryRules;
use Erpify\Tests\Support\CryptoReadRegistration;
use Erpify\Tests\Support\CryptoReadRegistrationCases;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
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

            public function __invoke(): void
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
        return CryptoReadRegistrationCases::all();
    }

    #[Test]
    public function aMissingContractPageIsAnEnvironmentFailureAndNotARegistrationProblem(): void
    {
        $files = CryptoReadRegistrationCases::completeRepository();
        unset($files['docs/api-error-contract.md']);
        $repository = $this->plantedRepository($files);

        $this->expectException(RuntimeException::class);

        CryptoReadRegistration::problems(
            ['Documents/Download.php' => ['test' => 'tests/Functional/Documents/DownloadTest.php']],
            $repository,
        );
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
