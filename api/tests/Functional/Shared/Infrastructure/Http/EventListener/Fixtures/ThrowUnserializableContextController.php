<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 3.3 — test-only controller throwing a `DomainException implements NotFound` whose
 * `context()` carries one closure, one `stdClass`, and one safe scalar. Lets the
 * `_throw-unserializable-context` route exercise the wire-level body sentinel substitution
 * (`'[unserializable]'`) end-to-end via a real Symfony kernel (functional + Behat).
 *
 * Wired by `api/config/services_test.yaml` (Fixtures resource glob) and routed by
 * `api/config/routes/test.yaml` (loaded only when `APP_ENV=test`).
 */
final class ThrowUnserializableContextController
{
    public function __invoke(): Response
    {
        $stdObject = new stdClass();
        $stdObject->field = 'value';

        $context = [
            'cb' => static fn (): int => 1,
            'proxy' => $stdObject,
            'safe_field' => 'kept',
        ];

        throw new class ('', 'Bank not found', $context) extends DomainException implements NotFound {
        };
    }
}
