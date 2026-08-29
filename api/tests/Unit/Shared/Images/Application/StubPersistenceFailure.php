<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use RuntimeException;

/**
 * The failure the repository doubles in this namespace raise, so a test can name exactly what it expects
 * instead of catching `RuntimeException`.
 *
 * That distinction is not cosmetic. `PHPUnit\Framework\AssertionFailedError` is itself a `RuntimeException`,
 * so a `catch (RuntimeException)` placed after a `$this->fail(...)` swallows the very assertion that was
 * meant to make the test fail — and the assertions inside the `catch` then run against the SUCCESSFUL path
 * and pass. Measured on the shape this replaces: a use case that stopped propagating a persistence failure
 * left the test green.
 *
 * @internal
 */
final class StubPersistenceFailure extends RuntimeException
{
}
