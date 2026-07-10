<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\CurrentSessionReference;
use Erpify\Iam\Session\Domain\SessionId;
use Override;

/**
 * In-memory {@see CurrentSessionReference} standing in for the session-bag adapter, so a test can assert which
 * correlation a use case writes (and read back a preset one).
 *
 * @internal
 */
final class RecordingCurrentSessionReference implements CurrentSessionReference
{
    public function __construct(private ?SessionId $reference = null)
    {
    }

    #[Override]
    public function get(): ?SessionId
    {
        return $this->reference;
    }

    #[Override]
    public function set(SessionId $sessionId): void
    {
        $this->reference = $sessionId;
    }
}
