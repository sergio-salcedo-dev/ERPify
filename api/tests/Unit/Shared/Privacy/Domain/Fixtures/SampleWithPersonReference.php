<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Privacy\Domain\Fixtures;

use Erpify\Shared\Privacy\Domain\PersonSubjectReference;

/**
 * Carries the reference marker on one property and not on its neighbour, so the reflection read is shown to
 * discriminate rather than to answer the same thing for everything it is handed.
 *
 * @internal
 */
final class SampleWithPersonReference
{
    #[PersonSubjectReference(erasedBy: 'src/Iam/Session/Application/PurgeUserSessions.php')]
    public string $userId = '';

    public string $organizationId = '';
}
