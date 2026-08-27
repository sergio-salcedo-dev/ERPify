<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Override;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * PSR-3 logger that always throws, so a test can prove NFR9's observability line is never
 * load-bearing for the rejection itself: the original domain exception must still propagate even
 * when the logger call fails.
 *
 * @internal
 */
final class ThrowingLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the signature is mandated by
     *                                                   Psr\Log\LoggerInterface; this double throws
     *                                                   unconditionally regardless of the arguments
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('Logging backend unavailable.');
    }
}
