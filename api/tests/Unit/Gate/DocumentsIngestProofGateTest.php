<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\DocumentsRestoreProofs;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
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
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the tree, the record validator and each planted shape
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
        $this->assertSame(
            [],
            DocumentsRestoreProofs::declarationsOutside(
                \dirname(__DIR__, 3) . '/config',
                \dirname(__DIR__, 3) . '/config/packages/documents.yaml',
            ),
            'documents.ingest_enabled is declared outside config/packages/documents.yaml, which this gate reads',
        );
    }

    /**
     * Any production value but the boolean literal false opens ingest, so each needs the proof a `true` needs.
     */
    #[DataProvider('provideANonFalseProductionValueOpensIngestCases')]
    public function testANonFalseProductionValueOpensIngest(mixed $value): void
    {
        $config = $this->withProductionSwitch($this->productionOpening('restic-offsite'), $value);

        $this->assertNotSame([], DocumentsRestoreProofs::ingestViolations($config, []), 'opens without a proof');
        $this->assertSame([], DocumentsRestoreProofs::ingestViolations($config, [self::VALID]), 'opens with one');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideANonFalseProductionValueOpensIngestCases(): iterable
    {
        yield 'an env placeholder' => ['%env(bool:DOCUMENTS_INGEST_ENABLED)%'];
        yield 'the string true' => ['true'];
        yield 'the integer one' => [1];
        yield 'the string one' => ['1'];
        yield 'null' => [null];
    }

    public function testAnAbsentProductionSwitchOpensIngest(): void
    {
        $this->assertNotSame(
            [],
            DocumentsRestoreProofs::ingestViolations(['parameters' => ['documents.backup_mechanism' => 'r']], []),
        );
    }

    public function testTheLiteralFalseKeepsIngestClosedWithoutAProof(): void
    {
        $config = $this->withProductionSwitch($this->productionOpening('none'), false);

        $this->assertSame([], DocumentsRestoreProofs::ingestViolations($config, []));
    }

    /**
     * @param array<string, string> $planted relative path => contents, beside the gate's own file
     */
    #[DataProvider('provideADeclarationOutsideTheReadFileIsRefusedCases')]
    public function testADeclarationOutsideTheReadFileIsRefused(array $planted, bool $refused): void
    {
        $root = \sys_get_temp_dir() . '/ingest-proof-' . \bin2hex(\random_bytes(6));
        $own = $root . '/packages/documents.yaml';
        $files = ['packages/documents.yaml' => "parameters:\n    documents.ingest_enabled: false\n"] + $planted;

        $filesystem = new Filesystem();

        try {
            foreach ($files as $path => $contents) {
                $filesystem->dumpFile($root . '/' . $path, $contents);
            }

            $this->assertSame(
                $refused ? \array_keys($planted) : [],
                DocumentsRestoreProofs::declarationsOutside($root, $own),
            );
        } finally {
            $filesystem->remove($root);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function provideADeclarationOutsideTheReadFileIsRefusedCases(): iterable
    {
        yield 'a prod override file' => [
            ['packages/prod/documents.yaml' => "parameters:\n    documents.ingest_enabled: true\n"],
            true,
        ];
        yield 'services.yaml parameters' => [
            ['services.yaml' => "parameters:\n    'documents.ingest_enabled': true\n"],
            true,
        ];
        yield 'a PHP loader' => [
            ['services.php' => "<?php\n\$parameters->set('documents.ingest_enabled', true);\n"],
            true,
        ];
        yield 'an XML loader' => [
            ['services.xml' => '<parameter key="documents.ingest_enabled">true</parameter>'],
            true,
        ];
        yield 'a reference is not a declaration' => [
            ['services.yaml' => "services:\n    x:\n        arguments: ['%documents.ingest_enabled%']\n"],
            false,
        ];
    }

    public function testDevAndTestOpenIngestAndProductionDoesNotByDefault(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/documents.yaml');
        $this->assertIsArray($config);

        $this->assertFalse(Dig::value($config, 'parameters', 'documents.ingest_enabled'));
        $this->assertTrue(Dig::value($config, 'when@dev', 'parameters', 'documents.ingest_enabled'));
        $this->assertSame('true', Dig::value($config, 'when@test', 'parameters', 'env(DOCUMENTS_INGEST_ENABLED)'));
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

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function withProductionSwitch(array $config, mixed $value): array
    {
        $prod = \is_array($config['when@prod'] ?? null) ? $config['when@prod'] : [];
        $parameters = \is_array($prod['parameters'] ?? null) ? $prod['parameters'] : [];
        $parameters['documents.ingest_enabled'] = $value;
        $prod['parameters'] = $parameters;
        $config['when@prod'] = $prod;

        return $config;
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
