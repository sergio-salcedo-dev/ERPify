<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\ObjectNotFound;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Storage\FilesystemDocumentStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The storage **the container wires**, against the root a deployment actually gives it.
 *
 * Every other test of the adapter hand-builds it over a temporary directory it created itself, which exercises
 * the contract and cannot learn whether the module works at all: if nothing provisions `staging/` and `final/`
 * under the configured root, every `stage()` fails permanently while the adapter's own tests stay green because
 * each one made its own root first. So this asserts what no hand-built adapter can — the port resolves to the
 * intended class, the root behind it is absolute and provisioned with both areas, and an object survives the
 * whole stage, promote, open, delete round trip there. It uses a fresh scope and removes both names again, so the
 * shared volume is left as it was found.
 *
 * @internal
 */
#[CoversClass(FilesystemDocumentStorage::class)]
final class DocumentStorageWiringTest extends KernelTestCase
{
    public function testTheWiredStorageRoundTripsAnObjectAgainstItsRealRoot(): void
    {
        self::bootKernel();

        $root = self::getContainer()->getParameter('erpify.documents.storage_root');
        $this->assertIsString($root);
        $this->assertStringStartsWith('/', $root, 'the root is an absolute path');
        $this->assertDirectoryExists($root . '/staging', 'the deployment provisions the staging area');
        $this->assertDirectoryExists($root . '/final', 'the deployment provisions the final area');

        $storage = self::getContainer()->get(DocumentStorage::class);
        $this->assertInstanceOf(FilesystemDocumentStorage::class, $storage, 'the port resolves to the local adapter');

        $scope = Uuid::v4()->toRfc4122();
        $bytes = \random_bytes(1024);
        $source = \fopen('php://memory', 'r+');
        $this->assertNotFalse($source);
        \fwrite($source, $bytes);
        \rewind($source);

        try {
            $storage->stage($scope, $source);
            $storage->promote($scope);

            $stream = $storage->open($scope);
            $this->assertSame($bytes, \stream_get_contents($stream), 'what is kept is what was given');
            \fclose($stream);
        } finally {
            $storage->delete(StorageArea::Staging, $scope);
            $storage->delete(StorageArea::Final, $scope);
        }

        $this->expectException(ObjectNotFound::class);

        $storage->open($scope);
    }
}
