<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\Dig;
use Erpify\Tests\Support\RepositoryRoot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Documents reaper and sweeps delete staged objects, and run on the scheduler. A scheduler that resolves a
 * different root, or none, finds an empty store and reports every deletion as done — so the service consuming the
 * Documents schedule must name the same storage root as `php` and mount the `document_storage` volume there.
 * Read from the root compose files, through the read-only `./` bind mount at `/app/repo`.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentsSchedulerStorageGateTest extends TestCase
{
    #[DataProvider('provideTheServiceResolvesAndMountsTheDocumentStorageCases')]
    public function testTheServiceResolvesAndMountsTheDocumentStorage(string $file, string $service): void
    {
        $root = RepositoryRoot::path() ?? $this->fail('The root compose files are unreachable (/app/repo bind mount).');
        $compose = Yaml::parseFile($root . '/' . $file);
        $this->assertIsArray($compose);

        $definition = Dig::array($compose, 'services', $service);
        $path = Dig::string($definition, 'environment', 'DOCUMENTS_STORAGE_PATH');
        $this->assertStringContainsString('/app/documents', $path);

        $volumes = $definition['volumes'] ?? [];
        $this->assertIsArray($volumes);
        $this->assertContains(
            'document_storage:/app/documents',
            $volumes,
            \sprintf('%s of %s does not mount the volume', $service, $file),
        );

        if ('compose.prod.yaml' === $file) {
            $this->assertContains(
                'scheduler_documents_maintenance',
                Dig::array($definition, 'command'),
                'the prod scheduler does not consume the Documents schedule',
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTheServiceResolvesAndMountsTheDocumentStorageCases(): iterable
    {
        yield 'prod scheduler_worker' => ['compose.prod.yaml', 'scheduler_worker'];
        yield 'dev messenger_worker' => ['compose.yaml', 'messenger_worker'];
        yield 'php' => ['compose.yaml', 'php'];
    }
}
