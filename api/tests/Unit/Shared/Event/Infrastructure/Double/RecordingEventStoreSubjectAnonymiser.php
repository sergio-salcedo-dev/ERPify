<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Double;

use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Override;

/**
 * Spy {@see EventStoreSubjectAnonymiser}: reports a fixed match count and records every call as
 * `[subjectId, pseudonym]`, so a test can assert not only that the business log was reached but that it was
 * handed the **actor pass's** pseudonym — one person must not split into two anonymous identities, and minting
 * a second one is the mistake a caller can make without any other assertion noticing.
 *
 * Its match count is deliberately independent of the two audit doubles': the three are different row sets, and
 * a test giving them the same number could not catch a sum where three separate counts were required.
 *
 * @internal
 */
final class RecordingEventStoreSubjectAnonymiser implements EventStoreSubjectAnonymiser
{
    /** @var list<array{subjectId: string, pseudonym: string}> */
    public array $calls = [];

    public function __construct(
        private readonly int $matchCount = 0,
    ) {
    }

    #[Override]
    public function anonymise(string $subjectId, string $pseudonym): int
    {
        $this->calls[] = ['subjectId' => $subjectId, 'pseudonym' => $pseudonym];

        return $this->matchCount;
    }
}
