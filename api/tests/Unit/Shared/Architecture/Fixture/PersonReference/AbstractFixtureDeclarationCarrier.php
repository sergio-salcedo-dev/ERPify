<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference;

use Erpify\Shared\Privacy\Domain\PersonSubjectReference;

/**
 * An abstract class carrying the marker on a property that is not a persisted column. It backs no table, so
 * the property can never reach the registry — which is exactly why the declaration sweep has to see it: an
 * erasure obligation written where nothing reads it must surface as a failure rather than fall outside the
 * sweep in silence.
 *
 * @internal test fixture
 */
abstract class AbstractFixtureDeclarationCarrier
{
    #[PersonSubjectReference(erasedBy: 'src/Iam/Session/Application/PurgeUserSessions.php')]
    public string $strandedUserId = '';
}
