<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only controller that throws a `DomainException` implementing the `NotFound` marker
 * with a `context()` carrying a mix of denylisted (`password`, `token`) and safe
 * (`safe_field`) keys, so the redaction denylist can be exercised end-to-end via a real
 * Symfony kernel (functional + Behat). The `'sensitive'` value lets the test assert the
 * substring is absent from the encoded body — defense-in-depth even if a key escaped by
 * some other path.
 *
 * Wired by `api/config/services_test.yaml` (Fixtures resource glob) and routed by
 * `api/config/routes/test.yaml` (loaded only when `APP_ENV=test`).
 */
final class ThrowDenylistedContextController
{
    public function __invoke(): Response
    {
        throw new class ('', 'Bank not found', ['password' => 'sensitive', 'token' => 'sensitive', 'safe_field' => 'kept']) extends DomainException implements NotFound {
        };
    }
}
