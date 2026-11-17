<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Infrastructure\Controller\ReadDocumentController;
use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\DocumentsRestoreProofs;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Production may serve real documents only behind a proven restore AND a restore drill that replayed the erasure
 * ledger: `documents.read_enabled` true in the tracked production configuration requires a valid `restore` record and
 * a valid `drill` record, both naming the backup mechanism that configuration declares. A deploy-injected value is
 * outside this gate — the switch is a literal in `config/packages/documents.yaml` for that reason, and the compiled
 * prod container is read as well, so a value set by a compiler pass or a key the byte scan misses still lands here.
 *
 * Its sibling {@see DocumentsIngestProofGateTest} holds the ingest switch to the `restore` record alone; the two
 * share the validator, so the record format is falsified there and the read rule here.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the tree, the controller binding and each planted shape
 */
#[CoversNothing]
final class DocumentsReadProofGateTest extends TestCase
{
    private const string RESTORE = <<<'YAML'
        kind: restore
        proved_by: an operator
        proved_at: '2026-11-06T10:00:00Z'
        mechanism: restic-offsite
        backup_ref: snapshot 1a2b3c
        originals_restored: true
        metadata_restored: true
        digest_set_verified: true
        decryption_verified: true
        result: passed
        YAML;

    private const string DRILL = <<<'YAML'
        kind: drill
        proved_by: an operator
        proved_at: '2026-11-07T10:00:00Z'
        mechanism: restic-offsite
        backup_ref: snapshot 4d5e6f
        originals_restored: true
        metadata_restored: true
        digest_set_verified: true
        decryption_verified: true
        ledger_replay_verified: true
        result: passed
        YAML;

    public function testTheTrackedProductionConfigurationHonoursTheRule(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/documents.yaml');
        $this->assertIsArray($config);

        $this->assertSame([], DocumentsRestoreProofs::readViolations($config, $this->recordsOnDisk()));
        $this->assertSame(
            [],
            DocumentsRestoreProofs::declarationsOutside(
                \dirname(__DIR__, 3) . '/config',
                \dirname(__DIR__, 3) . '/config/packages/documents.yaml',
                'documents.read_enabled',
            ),
            'documents.read_enabled is declared outside config/packages/documents.yaml, which this gate reads',
        );
    }

    /**
     * The value production actually runs with, read from the compiled prod container rather than from the file.
     */
    public function testTheCompiledProductionContainerHonoursTheRule(): void
    {
        $process = new Process(
            ['php', 'bin/console', 'debug:container', '--parameters', '--format=json', '--no-interaction'],
            \dirname(__DIR__, 3),
            ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
        );
        $process->mustRun();

        $parameters = \json_decode($process->getOutput(), true);
        $this->assertIsArray($parameters, "the prod container's parameters could not be read");
        $this->assertArrayHasKey('documents.read_enabled', $parameters);

        $this->assertSame([], DocumentsRestoreProofs::readViolations(['when@prod' => ['parameters' => [
            'documents.read_enabled' => $parameters['documents.read_enabled'],
            'documents.backup_mechanism' => $parameters['documents.backup_mechanism'] ?? null,
        ]]], $this->recordsOnDisk()));
    }

    /**
     * The 404 switch is read from exactly the parameter the checks above judge: bound to any other, the route could
     * open while the proof rule watched a parameter nothing reads.
     */
    public function testTheReadControllerReadsExactlyTheJudgedParameter(): void
    {
        $bindings = [];

        foreach ((new ReflectionMethod(ReadDocumentController::class, '__construct'))->getParameters() as $parameter) {
            foreach ($parameter->getAttributes(Autowire::class) as $attribute) {
                $bindings[$parameter->getName()] = $attribute->newInstance()->value;
            }
        }

        $this->assertSame(['readEnabled' => '%documents.read_enabled%'], $bindings);
    }

    public function testDevAndTestOpenReadsAndProductionDoesNotByDefault(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/documents.yaml');
        $this->assertIsArray($config);

        $this->assertFalse(Dig::value($config, 'parameters', 'documents.read_enabled'));
        $this->assertTrue(Dig::value($config, 'when@dev', 'parameters', 'documents.read_enabled'));
        $this->assertSame('true', Dig::value($config, 'when@test', 'parameters', 'env(DOCUMENTS_READ_ENABLED)'));
    }

    /**
     * Any production value but the boolean literal false opens reads, so each needs the full proof a `true` needs.
     */
    #[DataProvider('provideANonFalseProductionValueOpensReadsCases')]
    public function testANonFalseProductionValueOpensReads(mixed $value): void
    {
        $config = $this->productionOpening('restic-offsite', $value);

        $this->assertNotSame([], DocumentsRestoreProofs::readViolations($config, []), 'opens without a proof');
        $this->assertNotSame([], DocumentsRestoreProofs::readViolations($config, [self::RESTORE]), 'a restore');
        $this->assertNotSame([], DocumentsRestoreProofs::readViolations($config, [self::DRILL]), 'a drill');
        $this->assertSame([], DocumentsRestoreProofs::readViolations($config, [self::RESTORE, self::DRILL]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideANonFalseProductionValueOpensReadsCases(): iterable
    {
        yield 'true' => [true];
        yield 'an env placeholder' => ['%env(bool:DOCUMENTS_READ_ENABLED)%'];
        yield 'the string true' => ['true'];
        yield 'the integer one' => [1];
        yield 'null' => [null];
    }

    public function testAnAbsentProductionSwitchOpensReads(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::readViolations(['parameters' => ['documents.backup_mechanism' => 'r']], []),
        );
    }

    public function testTheLiteralFalseKeepsReadsClosedWithoutAProof(): void
    {
        $this->assertSame([], DocumentsRestoreProofs::readViolations($this->productionOpening('none', false), []));
    }

    public function testOpeningReadsWithNoMechanismFails(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::readViolations(
                $this->productionOpening('none', true),
                [self::RESTORE, self::DRILL],
            ),
        );
    }

    public function testProofsForAnotherMechanismDoNotCount(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::readViolations(
                $this->productionOpening('borg-offsite', true),
                [self::RESTORE, self::DRILL],
            ),
        );
    }

    public function testADrillForAnotherMechanismDoesNotCompleteARestoreForThisOne(): void
    {
        $drill = \str_replace('mechanism: restic-offsite', 'mechanism: borg-offsite', self::DRILL);

        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::readViolations(
                $this->productionOpening('restic-offsite', true),
                [self::RESTORE, $drill],
            ),
        );
    }

    public function testADrillWithoutALedgerReplayIsNotADrill(): void
    {
        $unreplayed = (string) \preg_replace('/^ledger_replay_verified:.*$/m', '', self::DRILL);
        $falsified = \str_replace('ledger_replay_verified: true', 'ledger_replay_verified: false', self::DRILL);

        foreach ([$unreplayed, $falsified] as $drill) {
            $this->assertNotSame([], DocumentsRestoreProofs::problemsWith($drill));
            $this->assertNotSame(
                [],
                DocumentsRestoreProofs::readViolations(
                    $this->productionOpening('restic-offsite', true),
                    [self::RESTORE, $drill],
                ),
            );
        }
    }

    public function testAValidDrillPassesTheValidator(): void
    {
        $this->assertSame([], DocumentsRestoreProofs::problemsWith(self::DRILL));
    }

    public function testARestoreNeedsNoLedgerReplay(): void
    {
        $this->assertSame([], DocumentsRestoreProofs::problemsWith(self::RESTORE));
    }

    public function testTwoRestoresDoNotStandInForTheDrill(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::readViolations(
                $this->productionOpening('restic-offsite', true),
                [self::RESTORE, self::RESTORE],
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function recordsOnDisk(): array
    {
        $root = RepositoryRoot::path() ?? $this->fail(
            'The repository root is unreachable (the read-only `./` bind mount at /app/repo), so the proof records '
            . 'cannot be read; restore the mount rather than relaxing this into a skip.',
        );
        $directory = $root . '/docs/runbooks/documents-restore-proofs';
        $this->assertDirectoryExists($directory, 'the proof-record directory this gate reads is gone');

        $records = [];

        foreach (\glob($directory . '/*.y*ml') ?: [] as $file) {
            $yaml = (string) \file_get_contents($file);
            $this->assertSame([], DocumentsRestoreProofs::problemsWith($yaml), \basename($file));
            $records[] = $yaml;
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    private function productionOpening(string $mechanism, mixed $value): array
    {
        return [
            'parameters' => ['documents.read_enabled' => false, 'documents.backup_mechanism' => 'none'],
            'when@prod' => ['parameters' => [
                'documents.read_enabled' => $value,
                'documents.backup_mechanism' => $mechanism,
            ]],
        ];
    }
}
