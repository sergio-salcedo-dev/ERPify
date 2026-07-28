<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http\Infrastructure\Fixtures;

use Symfony\Component\HttpFoundation\File\File;

/**
 * A payload whose file member is typed as a plain {@see File} rather than an `UploadedFile`.
 *
 * The exfiltration vector is constructibility from a path, which `File` already has — `UploadedFile`
 * only adds `originalName` and the `test` flag on top. A guard anchored on `UploadedFile` leaves this
 * shape unclaimed, and `File::__construct` even takes `checkPath: false`, so an absent path raises
 * nothing at all. This fixture is the type that would walk past such a guard.
 *
 * @internal
 */
final readonly class PlainFileBearingPayload
{
    public function __construct(
        public string $name,
        public ?File $image = null,
    ) {
    }
}
