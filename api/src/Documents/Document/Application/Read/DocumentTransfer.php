<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Read;

use Closure;

/**
 * A document whose original passed verification and whose read was audited: its size, for the response's
 * `Content-Length`, and the second pass that streams it. The caller sends headers first and then {@see sendTo()}.
 */
final readonly class DocumentTransfer
{
    /**
     * @param Closure(callable(string): void): bool $send
     */
    public function __construct(
        public int $sizeBytes,
        private Closure $send,
    ) {
    }

    /**
     * Unseals the original a second time, handing the plaintext to `$emit`, and re-checks it as it passes. The last
     * byte is held back until the whole stream has matched the row, so a transfer that fails verification ends one
     * byte short of the declared length — a broken transfer to any client — rather than as a complete body of the
     * wrong bytes.
     *
     * @param callable(string): void $emit
     *
     * @return bool whether the whole original was emitted; false means the transfer was aborted and alarmed
     */
    public function sendTo(callable $emit): bool
    {
        return ($this->send)($emit);
    }
}
