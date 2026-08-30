<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Override;

/**
 * A filesystem that ACCEPTS a write, reports success, and keeps fewer bytes than it was handed.
 *
 * It exists because a healthy temporary directory never produces the failure the round-trip verification
 * is there to catch: a write the substrate itself considered complete. Without this double the integrity
 * check is unfalsifiable — deleting it entirely leaves every other case green — so the test that asserts
 * "a corrupt write is refused" would prove only that a working filesystem works.
 *
 * It subclasses the library's own `Filesystem` rather than reimplementing `FilesystemOperator`: the
 * adapter under test reaches for three of that interface's eighteen methods, and eighteen lines of
 * delegation would be fifteen lines in which a second defect could hide.
 *
 * @internal
 */
final class PartiallyWritingFilesystem extends Filesystem
{
    public function __construct(string $root)
    {
        parent::__construct(new LocalFilesystemAdapter($root, lazyRootCreation: true));
    }

    /**
     * Keeps the first half of the payload — enough that the object exists, is readable and has a plausible
     * size, which is exactly the state a truncated write leaves behind.
     *
     * @param array<string, mixed> $config
     */
    #[Override]
    public function write(string $location, string $contents, array $config = []): void
    {
        parent::write($location, \substr($contents, 0, \intdiv(\strlen($contents), 2)), $config);
    }
}
