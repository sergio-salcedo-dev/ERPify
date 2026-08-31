<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use Override;

/**
 * Reproduces the one state the module's deletion order deliberately produces: the object was there when its
 * existence was established and is gone by the time it is read.
 *
 * `DeleteImage` unlinks the bytes BEFORE deleting the row, so that a crash can never leave a row promising
 * bytes that are not there — which means a reader racing a deletion meets exactly this. The window is
 * microseconds wide and impossible to hit on purpose from outside, so it is constructed here: the file is
 * removed from underneath, then the library's own refusal is raised with the message PHP produces for it.
 *
 * @internal test support
 */
final class VanishingFilesystem extends Filesystem
{
    public function __construct(private readonly string $root)
    {
        parent::__construct(new LocalFilesystemAdapter($root, lazyRootCreation: true));
    }

    #[Override]
    public function read(string $location): string
    {
        $path = $this->root . '/' . $location;

        if (\is_file($path)) {
            \unlink($path);
        }

        throw UnableToReadFile::fromLocation(
            $location,
            'file_get_contents(): Failed to open stream: No such file or directory',
        );
    }
}
