<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http\Infrastructure\Fixtures;

use SplFileInfo;

/**
 * A payload whose file member is typed one level above Symfony's own file classes.
 *
 * `SplFileInfo` is where constructibility from a path begins — `filename` is its constructor parameter,
 * and `FileValidator` accepts any `Stringable` as a path, so the bytes at the named location get inspected
 * just the same. A guard anchored on `File` claims neither this shape nor `SplFileObject`, which opens the
 * named file on construction. This fixture is the type that would walk past such a guard.
 *
 * @internal
 */
final readonly class SplFileBearingPayload
{
    public function __construct(
        public string $name,
        public ?SplFileInfo $image = null,
    ) {
    }
}
