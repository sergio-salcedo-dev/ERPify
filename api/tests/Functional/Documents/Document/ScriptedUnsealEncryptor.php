<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Shared\Crypto\Application\StreamEnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\StreamTransferFailed;
use LogicException;
use Override;
use Throwable;

/**
 * A {@see StreamEnvelopeEncryptor} whose `unseal()` ignores the sealed stream and pushes a scripted plaintext into the
 * sink in fixed-size chunks, as the real one does: a sink that refuses a chunk (writes fewer bytes) ends the call with
 * {@see StreamTransferFailed}. It counts the chunks it offered, so a test can prove a reader stopped early, and it can
 * raise a chosen throwable on a chosen call, so a test can reach a failure the real sealer does not produce on demand.
 *
 * @internal
 */
final class ScriptedUnsealEncryptor implements StreamEnvelopeEncryptor
{
    public int $chunksOffered = 0;

    private int $calls = 0;

    public function __construct(
        private readonly string $plaintext,
        private readonly int $chunkSize,
        private readonly ?Throwable $failure = null,
        private readonly int $failOnCall = 0,
    ) {
    }

    #[Override]
    public function seal(EncryptionScopeId $scope, $plaintext, $sealed, string $aad): void
    {
        throw new LogicException('The scripted encryptor only unseals.');
    }

    #[Override]
    public function unseal(EncryptionScopeId $scope, $sealed, $plaintext, string $aad): void
    {
        ++$this->calls;

        if ($this->failure instanceof Throwable && $this->failOnCall === $this->calls) {
            throw $this->failure;
        }

        foreach (\str_split($this->plaintext, \max(1, $this->chunkSize)) as $chunk) {
            ++$this->chunksOffered;

            if (\strlen($chunk) !== $this->writeQuietly($plaintext, $chunk)) {
                throw StreamTransferFailed::forScope($scope);
            }
        }
    }

    /**
     * A sink that refuses a write (the stream callback answering false) makes `fwrite()` warn as well as return a
     * short count; the count is the answer, so the warning is swallowed for the call.
     *
     * @param resource $plaintext
     */
    private function writeQuietly($plaintext, string $chunk): int
    {
        \set_error_handler(static fn (): bool => true);

        try {
            return \fwrite($plaintext, $chunk) ?: 0;
        } finally {
            \restore_error_handler();
        }
    }
}
