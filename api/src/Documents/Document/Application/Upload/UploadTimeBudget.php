<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

/**
 * Re-arms the time the running request may still take. A PHP time limit counts wall-clock time under the
 * server's worker threads and a fatal on expiry kills the thread with nothing caught, so a long upload re-arms
 * it at each heartbeat and the loop aborts cleanly on its own deadline long before the limit is reached.
 */
interface UploadTimeBudget
{
    public function arm(int $seconds): void;
}
