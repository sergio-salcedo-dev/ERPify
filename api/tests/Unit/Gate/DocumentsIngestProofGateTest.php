<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\DocumentsRestoreProofs;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Production may hold real evidence only behind a proven restore: `documents.ingest_enabled` true in the
 * tracked production configuration requires a valid restore-proof record whose `mechanism` equals the backup
 * mechanism that configuration declares. A deploy-injected value is outside this gate — the switch is a literal
 * in `config/packages/documents.yaml` for that reason.
 *
 * The tree half reads the real configuration and every record on disk; the rule half falsifies the validator
 * and the switch rule on planted inputs, including a historical record for another mechanism.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentsIngestProofGateTest extends TestCase
{
    private const string VALID = <<<'YAML'
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

    public function testTheTrackedProductionConfigurationHonoursTheRule(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/documents.yaml');
        $this->assertIsArray($config);

        $records = [];

        foreach (\glob($this->proofDirectory() . '/*.y*ml') ?: [] as $file) {
            $yaml = (string) \file_get_contents($file);
            $this->assertSame([], DocumentsRestoreProofs::problemsWith($yaml), \basename($file));
            $records[] = $yaml;
        }

        $this->assertSame([], DocumentsRestoreProofs::ingestViolations($config, $records));
    }

    public function testDevAndTestOpenIngestAndProductionDoesNotByDefault(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/documents.yaml');
        $this->assertIsArray($config);

        $this->assertFalse(Dig::value($config, 'parameters', 'documents.ingest_enabled'));
        $this->assertTrue(Dig::value($config, 'when@dev', 'parameters', 'documents.ingest_enabled'));
        $this->assertTrue(Dig::value($config, 'when@test', 'parameters', 'documents.ingest_enabled'));
    }

    public function testAValidRecordPasses(): void
    {
        $this->assertSame([], DocumentsRestoreProofs::problemsWith(self::VALID));
    }

    #[DataProvider('provideAnInvalidRecordIsRefusedCases')]
    public function testAnInvalidRecordIsRefused(string $yaml): void
    {
        $this->assertNotSame([], DocumentsRestoreProofs::problemsWith($yaml));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnInvalidRecordIsRefusedCases(): iterable
    {
        foreach (DocumentsRestoreProofs::FIELDS as $field) {
            yield 'without ' . $field => [(string) \preg_replace('/^' . $field . ':.*$/m', '', self::VALID)];
        }

        yield 'a failed result' => [\str_replace('result: passed', 'result: failed', self::VALID)];
        yield 'an unknown kind' => [\str_replace('kind: restore', 'kind: guess', self::VALID)];
        yield 'decryption not verified' => [\str_replace(
            'decryption_verified: true',
            'decryption_verified: false',
            self::VALID,
        )];
        yield 'not YAML' => ["kind: [\n"];
    }

    public function testProductionOpeningIngestWithoutAProofFails(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::ingestViolations($this->productionOpening('restic-offsite'), []),
        );
    }

    public function testAProofForAnotherMechanismDoesNotCount(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::ingestViolations($this->productionOpening('borg-offsite'), [self::VALID]),
        );
    }

    public function testADrillIsNotARestoreProof(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::ingestViolations(
                $this->productionOpening('restic-offsite'),
                [\str_replace('kind: restore', 'kind: drill', self::VALID)],
            ),
        );
    }

    public function testAMatchingValidRestoreProofOpensIngest(): void
    {
        $this->assertSame(
            [],
            DocumentsRestoreProofs::ingestViolations($this->productionOpening('restic-offsite'), [self::VALID]),
        );
    }

    public function testOpeningIngestWithNoMechanismFails(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::ingestViolations($this->productionOpening('none'), [self::VALID]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productionOpening(string $mechanism): array
    {
        return [
            'parameters' => ['documents.ingest_enabled' => false, 'documents.backup_mechanism' => 'none'],
            'when@prod' => ['parameters' => [
                'documents.ingest_enabled' => true,
                'documents.backup_mechanism' => $mechanism,
            ]],
        ];
    }

    private function proofDirectory(): string
    {
        $root = RepositoryRoot::path() ?? $this->fail(
            'The repository root is unreachable (the read-only `./` bind mount at /app/repo), so the proof records '
            . 'cannot be read; restore the mount rather than relaxing this into a skip.',
        );
        $directory = $root . '/docs/runbooks/documents-restore-proofs';
        $this->assertDirectoryExists($directory, 'the proof-record directory this gate reads is gone');

        return $directory;
    }
}
