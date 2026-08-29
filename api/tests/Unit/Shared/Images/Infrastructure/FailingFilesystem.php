<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Override;
use Throwable;

/**
 * A filesystem that raises a supplied failure from one named operation and behaves normally in every
 * other, so a test can choose which of the library's exceptions crosses the adapter's translation edge.
 *
 * The failure is a parameter rather than a fixed type because the property under test is the MAPPING:
 * a library exception becomes a verdict of this module's own vocabulary, and one that is not the
 * library's must stay untranslated rather than being disguised as a storage verdict.
 *
 * Subclasses the library's `Filesystem` for the reason its sibling records.
 *
 * @internal
 */
final class FailingFilesystem extends Filesystem
{
    private function __construct(
        string $root,
        private readonly string $failingOperation,
        private readonly Throwable $failure,
    ) {
        parent::__construct(new LocalFilesystemAdapter($root, lazyRootCreation: true));
    }

    public static function raisingFrom(string $operation, Throwable $failure, string $root): self
    {
        return new self($root, $operation, $failure);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Override]
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->raiseWhenAskedFor('write');

        parent::write($location, $contents, $config);
    }

    #[Override]
    public function read(string $location): string
    {
        $this->raiseWhenAskedFor('read');

        return parent::read($location);
    }

    #[Override]
    public function delete(string $location): void
    {
        $this->raiseWhenAskedFor('delete');

        parent::delete($location);
    }

    private function raiseWhenAskedFor(string $operation): void
    {
        if ($operation === $this->failingOperation) {
            throw $this->failure;
        }
    }
}
