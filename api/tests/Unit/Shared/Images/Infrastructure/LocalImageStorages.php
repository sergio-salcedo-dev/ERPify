<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Clock\Domain\NativeClock;
use Erpify\Shared\Images\Application\FailureSignalWindow;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\LoggerInterface;

/**
 * Builds the local-filesystem adapter the way the application wires it, so a test naming a root does not
 * also have to name every collaborator the constructor happens to take.
 *
 * **The seam exists because the churn is real, not anticipated.** Adding one collaborator to
 * `FlysystemImageStorage` meant editing thirteen construction sites across six test files, and each of them
 * had to learn about a `Clock` it has no opinion about. That is the cost a factory removes: the next
 * collaborator lands here and nowhere else.
 *
 * Every argument is optional and every default is the neutral one, so a test overrides exactly the thing its
 * case is about — a filesystem that corrupts writes, a logger it will read back — and stays silent about the
 * rest. The window is always FRESH: sharing one across cases would let a suppressed signal in one test
 * decide what another observes.
 *
 * @internal test support
 */
final class LocalImageStorages
{
    public static function rootedAt(
        string $root,
        ?FilesystemOperator $filesystem = null,
        ?LoggerInterface $logger = null,
    ): FlysystemImageStorage {
        return new FlysystemImageStorage(
            $filesystem ?? new Filesystem(new LocalFilesystemAdapter($root, lazyRootCreation: true)),
            $root,
            $logger ?? new RecordingLogger(),
            new FailureSignalWindow(new NativeClock()),
        );
    }
}
