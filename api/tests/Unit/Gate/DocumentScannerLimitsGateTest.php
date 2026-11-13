<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Domain\ScanPolicy;
use Erpify\Documents\Document\Domain\UploadLimits;
use Erpify\Tests\Support\ComposeStackCommands;
use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Documents scanner is configured so that a stream it cannot fully examine is an ALERT and never a clean answer,
 * and so that the largest accepted original fits in what it will examine.
 *
 * Read as text from `api/clamav/clamd.conf` and the root compose files: the subject is the declaration, which is the
 * half that drifts silently. A green proves the committed configuration, not the deployed daemon — that clamd honours
 * it was measured once by the scanner spike (docs/deployment-guide.md, "Document scanner") and by the opt-in
 * `make php.scanner.integration` run, which sends a stream one byte past the ceiling and an archive that exceeds a
 * limit to a real clamd.
 *
 * Three claims, each with the reading that makes it falsifiable:
 *  - the limits (`StreamMaxLength`, `MaxFileSize`, `MaxScanSize`) are at least {@see UploadLimits::MAX_ORIGINAL_BYTES},
 *    `MaxFiles` and `MaxRecursion` are set, and `AlertExceedsMax` is on — otherwise an oversize original, or an
 *    archive past a limit, is reported clean or refused with an error that reads as an outage;
 *  - `MaxScanTime` equals {@see ScanPolicy::CLAMD_MAX_SCAN_TIME_MILLISECONDS} and stays below the lease and the
 *    deadline, so a scan clamd gives up on answers before the lease the worker holds lapses;
 *  - plaintext never reaches a persistent layer: clamd's temporary directory is a tmpfs of the scanner service,
 *    `memswap_limit` equals `mem_limit`, core dumps are off for the scanner and every PHP service that unseals, and
 *    the service is pinned by digest and bounded in its logs.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentScannerLimitsGateTest extends TestCase
{
    private const string CONFIG = __DIR__ . '/../../../clamav/clamd.conf';

    #[Test]
    public function theStreamCeilingAndTheFileLimitsAdmitTheLargestOriginal(): void
    {
        $config = $this->config();

        foreach (['StreamMaxLength', 'MaxFileSize', 'MaxScanSize'] as $directive) {
            $this->assertGreaterThanOrEqual(
                UploadLimits::MAX_ORIGINAL_BYTES,
                $this->bytesOf($config, $directive),
                $directive . ' is below UploadLimits::MAX_ORIGINAL_BYTES, so an accepted original could not be scanned',
            );
        }
    }

    #[Test]
    public function aLimitExceededIsAnAlertAndTheArchiveLimitsAreSet(): void
    {
        $config = $this->config();

        $this->assertSame('yes', $config['AlertExceedsMax'] ?? null, 'without it a limit is silently clean');
        $this->assertGreaterThan(0, (int) ($config['MaxFiles'] ?? 0), 'MaxFiles 0 disables the limit');
        $this->assertGreaterThan(0, (int) ($config['MaxRecursion'] ?? 0));
    }

    #[Test]
    public function theScanTimeIsTheOneThePolicyNamesAndStaysBelowTheLeaseAndTheDeadline(): void
    {
        $milliseconds = (int) ($this->config()['MaxScanTime'] ?? 0);

        $this->assertSame(ScanPolicy::CLAMD_MAX_SCAN_TIME_MILLISECONDS, $milliseconds);
        $this->assertGreaterThan(0, $milliseconds, 'MaxScanTime 0 disables the limit');
        $this->assertLessThan(ScanPolicy::LEASE_SECONDS * 1000, $milliseconds);
        $this->assertLessThan(ScanPolicy::DEADLINE_SECONDS * 1000, $milliseconds);
    }

    #[Test]
    public function aSignatureReloadPausesScanningInsteadOfDoublingMemory(): void
    {
        $this->assertSame('no', $this->config()['ConcurrentDatabaseReload'] ?? null);
    }

    #[Test]
    #[DataProvider('provideTheStacks')]
    public function theMergedScannerServiceKeepsPlaintextOffPersistentLayers(string $overlay): void
    {
        $scanner = $this->service($overlay, 'scanner');
        $temporary = (string) ($this->config()['TemporaryDirectory'] ?? '');

        $this->assertNotSame('', $temporary, 'clamd would spool to its default directory, on the container layer');
        $this->assertTrue(
            $this->mountsATmpfsAt(Dig::array($scanner, 'tmpfs'), $temporary),
            'TemporaryDirectory is not a tmpfs of the scanner service',
        );
        $this->assertSame('2g', Dig::string($scanner, 'mem_limit'));
        $this->assertSame(
            Dig::string($scanner, 'mem_limit'),
            Dig::string($scanner, 'memswap_limit'),
            'with swap the container can page plaintext out to disk',
        );
        $this->assertSame(0, Dig::int($scanner, 'ulimits', 'core', 'hard'));
        $this->assertSame(0, Dig::int($scanner, 'ulimits', 'core', 'soft'));
        $this->assertMatchesRegularExpression(
            '/^clamav\/clamav:[\w.]+@sha256:[0-9a-f]{64}$/D',
            Dig::string($scanner, 'image'),
            'the signature engine is pinned by digest',
        );
        $this->assertSame(
            ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '5']],
            Dig::array($scanner, 'logging'),
            'the scanner log is bounded like every other',
        );
        $this->assertContains(
            './api/clamav/clamd.conf:/etc/clamav/clamd.conf:ro',
            Dig::array($scanner, 'volumes'),
            'the configuration this gate reads is the one the container runs',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTheStacks(): iterable
    {
        yield 'dev stack' => ['compose.dev.yaml'];
        yield 'prod stack' => ['compose.prod.yaml'];
    }

    #[Test]
    #[DataProvider('provideTheStacks')]
    public function everyPhpServiceThatUnsealsHasCoreDumpsOffInTheMergedStack(string $overlay): void
    {
        $services = 'compose.prod.yaml' === $overlay
            ? ['php', 'messenger_worker', 'scheduler_worker']
            : ['php', 'messenger_worker'];

        foreach ($services as $service) {
            $definition = $this->service($overlay, $service);

            $this->assertSame(0, Dig::int($definition, 'ulimits', 'core', 'hard'), $service . ' of ' . $overlay);
            $this->assertSame(0, Dig::int($definition, 'ulimits', 'core', 'soft'), $service . ' of ' . $overlay);
        }
    }

    #[Test]
    public function theProductionScannerIsAlwaysStartedAndReachableOnlyOverTheBackendAndItsOwnEgressNetwork(): void
    {
        $prod = $this->service('compose.prod.yaml', 'scanner');
        $networks = Dig::array($prod, 'networks');

        $this->assertArrayNotHasKey('profiles', $prod, 'a profile in production is a scanner nobody started');
        $this->assertContains('backend', $networks, 'the workers reach it on the backend network');
        $this->assertNotContains(
            'frontend',
            $networks,
            "clamd's command port is unauthenticated and must not share a network with php and pwa",
        );

        foreach (['php', 'pwa', 'database', 'messenger_worker', 'scheduler_worker'] as $other) {
            $this->assertNotContains(
                'scanner_egress',
                Dig::array($this->service('compose.prod.yaml', $other), 'networks'),
                $other . " must not join the scanner's egress network",
            );
        }
    }

    #[Test]
    public function theDevStackGatesTheScannerBehindAProfile(): void
    {
        $this->assertSame(['scanner'], Dig::array($this->service('compose.dev.yaml', 'scanner'), 'profiles'));
    }

    /**
     * @return array<string, string>
     */
    private function config(): array
    {
        $contents = \file_get_contents(self::CONFIG);
        $this->assertIsString($contents, 'api/clamav/clamd.conf is unreadable');

        $config = [];

        foreach (\explode("\n", $contents) as $line) {
            $line = \trim($line);

            if ('' === $line || \str_starts_with($line, '#')) {
                continue;
            }

            $parts = \preg_split('/\s+/', $line, 2) ?: [];
            $config[(string) \array_first($parts)] = $parts[1] ?? '';
        }

        return $config;
    }

    /**
     * @param array<string, string> $config
     */
    private function bytesOf(array $config, string $directive): int
    {
        $value = $config[$directive] ?? null;
        $this->assertIsString($value, $directive . ' is not set, so clamd applies its own default');
        $isSize = \preg_match('/^(?<n>\d+)(?<unit>[KkMmGg]?)$/D', $value, $matches);
        $this->assertSame(1, $isSize, $directive . ' is not a size');

        return (int) $matches['n'] * match (\strtolower($matches['unit'])) {
            'k' => 1024,
            'm' => 1_048_576,
            'g' => 1_073_741_824,
            default => 1,
        };
    }

    /**
     * @param array<array-key, mixed> $tmpfs
     */
    private function mountsATmpfsAt(array $tmpfs, string $path): bool
    {
        return \array_any(
            $tmpfs,
            static fn ($entry): bool => \is_string($entry)
                && ($entry === $path || \str_starts_with($entry, $path . ':')),
        );
    }

    /**
     * A service as `docker compose -f compose.yaml -f <overlay>` merges it.
     *
     * @return array<array-key, mixed>
     */
    private function service(string $overlay, string $service): array
    {
        $root = RepositoryRoot::path() ?? $this->fail('The root compose files are unreachable (/app/repo bind mount).');

        return ComposeStackCommands::serviceOf($service, $root . '/compose.yaml', $root . '/' . $overlay);
    }
}
