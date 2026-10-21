<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Double;

use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
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

    /** Set when a test is asserting where the pass falls among the tables the chain locks. */
    public ?LockOrderJournal $lockOrderJournal = null;

    public function __construct(
        private readonly int $matchCount = 0,
    ) {
    }

    #[Override]
    public function anonymise(SubjectPseudonymisation $pseudonymisation): int
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::EVENT_STORE);
        $this->calls[] = [
            'subjectId' => $pseudonymisation->subjectId,
            'pseudonym' => $pseudonymisation->pseudonym,
        ];

        return $this->matchCount;
    }
}
