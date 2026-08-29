<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The storage **the container wires**, against the root a deployment actually gives it.
 *
 * Every other test in this module hand-builds the adapter over a temporary directory it created itself,
 * which is the right way to exercise the contract and the wrong way to learn whether the module works at
 * all. The gap that leaves is not theoretical: the wired root is `<STORAGE_LOCAL_PATH>/images` and nothing
 * created it, so every `store()`, `read()` and `delete()` in every environment failed permanently — the
 * whole deletion pipeline dead on arrival — while 90 tests stayed green because each one had `mkdir`-ed
 * its own root first.
 *
 * So this asserts the two things no hand-built adapter can: the alias resolves to the intended class, and
 * the root behind it is provisioned and writable. It stores under a fresh identifier and removes it again,
 * so the shared volume is left as it was found.
 *
 * @internal
 */
#[CoversClass(FlysystemImageStorage::class)]
final class ImageStorageWiringTest extends KernelTestCase
{
    public function testTheWiredStorageRoundTripsAnImageAgainstItsRealRoot(): void
    {
        self::bootKernel();

        $storage = self::getContainer()->get(ImageStorage::class);
        $this->assertInstanceOf(FlysystemImageStorage::class, $storage, 'the port resolves to the local adapter');

        $identifier = ImageId::generate();
        $bytes = \random_bytes(1024);

        try {
            $storage->store($identifier, $bytes);

            $this->assertSame($bytes, $storage->read($identifier), 'what the wired storage keeps is what it was given');
        } finally {
            $storage->delete($identifier);
        }

        try {
            $storage->read($identifier);
            $this->fail('the probe object must not survive the test');
        } catch (ImageBytesNotFound) {
            $this->addToAssertionCount(1);
        }
    }
}
