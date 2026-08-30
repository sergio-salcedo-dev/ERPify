<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Override;

/**
 * A filesystem that creates the object, keeps part of the payload, and THEN refuses the write.
 *
 * It reproduces what the local adapter really does rather than what a naive double would: `write()` goes
 * through `file_put_contents()`, whose `open(2)` carries `O_CREAT|O_TRUNC`, so the file exists under the
 * final key before a single byte is written and survives a write that fails part-way — `ENOSPC`, a quota,
 * an I/O error. A double that raises BEFORE touching the disk leaves nothing behind and so cannot observe
 * whether the adapter cleans up; it would report the residue as absent and pass whatever the adapter did.
 *
 * @internal
 */
final class FailingAfterPartialWriteFilesystem extends Filesystem
{
    public function __construct(private readonly string $root)
    {
        parent::__construct(new LocalFilesystemAdapter($root, lazyRootCreation: true));
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Override]
    public function write(string $location, string $contents, array $config = []): void
    {
        parent::write($location, \substr($contents, 0, \intdiv(\strlen($contents), 2)), $config);

        throw UnableToWriteFile::atLocation(
            $this->root . '/' . $location,
            'file_put_contents(): Write of 64 bytes failed with errno=28 No space left on device',
        );
    }
}
