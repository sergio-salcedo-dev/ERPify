<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Override;
use Throwable;

/**
 * Recording {@see DomainEventHandlerDeduplicator} that records call counts and can simulate a
 * release failure, so a test can pin a handler's claim/send/release flow without a database.
 *
 * @internal
 */
final class RecordingDomainEventHandlerDeduplicator implements DomainEventHandlerDeduplicator
{
    public int $claimCalls = 0;

    public int $releaseCalls = 0;

    private bool $claimResult = true;

    private ?Throwable $releaseFailure = null;

    public static function granting(): self
    {
        return new self();
    }

    public static function rejecting(): self
    {
        $fake = new self();
        $fake->claimResult = false;

        return $fake;
    }

    public static function failingToRelease(Throwable $releaseFailure): self
    {
        $fake = new self();
        $fake->releaseFailure = $releaseFailure;

        return $fake;
    }

    #[Override]
    public function claim(string $eventId, string $handlerKey): bool
    {
        ++$this->claimCalls;

        return $this->claimResult;
    }

    #[Override]
    public function release(string $eventId, string $handlerKey): void
    {
        ++$this->releaseCalls;

        if ($this->releaseFailure instanceof Throwable) {
            throw $this->releaseFailure;
        }
    }
}
