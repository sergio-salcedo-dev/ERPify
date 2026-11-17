<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Resource;

/**
 * Wire contract of a LATER page of a custody trail (`afterSeq` given): the rows and the next cursor, and nothing of the
 * document, so a long walk opens its name once, on the first page.
 */
final readonly class DocumentCustodyPageResource
{
    /**
     * @param list<array{seq: int, event: string, actorId: ?string, reason: ?string, occurredAt: string}> $entries
     */
    public function __construct(
        public array $entries,
        public ?int $nextAfterSeq,
    ) {
    }
}
