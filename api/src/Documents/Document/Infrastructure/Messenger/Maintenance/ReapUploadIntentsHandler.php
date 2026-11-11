<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One reaper tick. Bounded per tick, and safe under a duplicate tick: each intent is decided under its own lock
 * and rechecked there, so two ticks racing over one intent end it once.
 */
#[AsMessageHandler]
final readonly class ReapUploadIntentsHandler
{
    private const int BATCH = 500;

    public function __construct(
        private ReapUploadIntents $reapUploadIntents,
    ) {
    }

    public function __invoke(ReapUploadIntentsMessage $message): void
    {
        unset($message);

        $this->reapUploadIntents->reap(self::BATCH);
    }
}
