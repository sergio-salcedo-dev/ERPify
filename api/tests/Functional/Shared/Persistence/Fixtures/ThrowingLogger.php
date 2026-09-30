<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Override;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A PSR-3 logger whose sink is broken — an unwritable stream, a full disk — so a test can assert that losing a
 * report never fails the caller that was reporting.
 *
 * @internal
 */
final class ThrowingLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException(\sprintf(
            'the log sink is unwritable (a %s record "%s" with %d context keys was refused)',
            \get_debug_type($level),
            $message,
            \count($context),
        ));
    }
}
