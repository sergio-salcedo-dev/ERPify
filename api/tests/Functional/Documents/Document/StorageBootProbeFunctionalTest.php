<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Infrastructure\Cli\ProbeDocumentStorageCommand;
use Erpify\Documents\Document\Infrastructure\Storage\StorageBootProbe;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The boot probe every container touching the document volume runs before it starts, against the real volume:
 * a storage that cannot promote fails the command — the entrypoint's refusal to start — and neither outcome
 * leaves its probe object behind in either area.
 *
 * @internal
 */
#[CoversClass(StorageBootProbe::class)]
#[CoversClass(ProbeDocumentStorageCommand::class)]
final class StorageBootProbeFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAStorageThatCannotPromoteFailsTheProbeAndLeavesNothing(): void
    {
        $storage = (new ScriptedDocumentStorage($this->service(DocumentStorage::class)))->failEveryPromote();

        $this->assertSame(Command::FAILURE, $this->probeWith($storage));
        $this->assertNothingLeftBy($storage);
    }

    public function testASucceedingProbeLeavesNothing(): void
    {
        $storage = new ScriptedDocumentStorage($this->service(DocumentStorage::class));

        $this->assertSame(Command::SUCCESS, $this->probeWith($storage));
        $this->assertNothingLeftBy($storage);
    }

    private function probeWith(ScriptedDocumentStorage $storage): int
    {
        return (new CommandTester(new ProbeDocumentStorageCommand(new StorageBootProbe($storage))))->execute([]);
    }

    private function assertNothingLeftBy(ScriptedDocumentStorage $storage): void
    {
        $this->assertCount(1, $storage->staged, 'the probe staged one object');

        foreach (StorageArea::cases() as $area) {
            $this->assertFalse($storage->has($area, $storage->staged[0]), \sprintf('left in %s', $area->value));
        }
    }
}
