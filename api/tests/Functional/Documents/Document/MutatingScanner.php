<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use Override;
use Throwable;

/**
 * A scanner that accepts the stream and, once the first chunk has arrived, runs a hook — the place a test changes the
 * row under a scan in flight, so the next heartbeat meets the change. A one-chunk file has no next heartbeat, and the
 * scan then ends at its verdict (or, with `$thenFail`, with that failure).
 */
final class MutatingScanner implements MalwareScanner
{
    /**
     * @param callable(): mixed $afterFirstChunk
     */
    public function __construct(
        private $afterFirstChunk,
        private readonly ?Throwable $thenFail = null,
    ) {
    }

    #[Override]
    public function probe(): bool
    {
        return true;
    }

    #[Override]
    public function scan(callable $producer, callable $keepAlive): ScanVerdict
    {
        $first = true;
        $hook = $this->afterFirstChunk;
        $sink = WriteCallbackStream::open(static function () use (&$first, $hook): bool {
            if ($first) {
                $first = false;
                $hook();
            }

            return true;
        });

        try {
            $producer($sink);
        } finally {
            \fclose($sink);
        }

        return $this->thenFail instanceof Throwable ? throw $this->thenFail : ScanVerdict::Clean;
    }
}
