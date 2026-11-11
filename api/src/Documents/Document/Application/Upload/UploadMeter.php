<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use Erpify\Documents\Document\Domain\UploadLimits;
use HashContext;
use LogicException;
use Throwable;

/**
 * Reads the body of an evidence PUT on behalf of the sealer: it counts the bytes, hashes the plaintext as it
 * passes, keeps the upload's lease alive, and refuses to hand over a single byte past the declared size.
 *
 * **The first extra byte is never sealed.** A read that would carry the count past the declared size is not
 * returned at all: the meter records an overflow and answers a failed read, so the sealer stops before that
 * chunk is sealed or staged. A body that ends short is the opposite case and needs no help here — the caller
 * compares {@see bytesRead()} with the declared size once the stream ends, because a body the transport cut
 * off ends with a clean end-of-stream that nothing at this layer can tell from a complete one (measured).
 *
 * **The heartbeat runs between reads**, at most once per {@see UploadLimits::HEARTBEAT_INTERVAL_SECONDS}: it
 * renews the lease under the intent row's lock and re-arms the request's time budget. When it reports the
 * upload is no longer live — its lease or deadline passed, or something expired it — the meter answers a
 * failed read and the writer aborts, so a stream nobody holds any more stops consuming a thread.
 *
 * It is exposed to the sealer as a stream resource ({@see open()}), because that is what the sealer reads.
 */
final class UploadMeter
{
    public const string ABORT_OVERFLOW = 'overflow';

    public const string ABORT_LEASE_LOST = 'lease-lost';

    public const string ABORT_SOURCE_FAILED = 'source-failed';

    public const string ABORT_HEARTBEAT_FAILED = 'heartbeat-failed';

    private int $bytesRead = 0;

    private ?string $abortReason = null;

    private bool $sourceEnded = false;

    private ?Throwable $heartbeatFailure = null;

    private readonly HashContext $hash;

    private float $lastHeartbeat;

    /** @var resource */
    private $source;

    /** @var callable(): bool */
    private $heartbeat;

    /** @var callable(): float */
    private $monotonicSeconds;

    /**
     * @param resource           $source                   the request body
     * @param callable(): bool   $heartbeat                renews the lease; false when the upload is no longer live
     * @param ?callable(): float $monotonicSeconds         a monotonic clock in seconds, injectable for tests
     * @param int                $heartbeatIntervalSeconds the least time between two heartbeats
     */
    public function __construct(
        $source,
        private readonly int $declaredSize,
        callable $heartbeat,
        ?callable $monotonicSeconds = null,
        private readonly int $heartbeatIntervalSeconds = UploadLimits::HEARTBEAT_INTERVAL_SECONDS,
    ) {
        if (!\is_resource($source)) {
            throw new LogicException('The upload body must be an open stream.');
        }

        $this->source = $source;
        $this->heartbeat = $heartbeat;
        $this->monotonicSeconds = $monotonicSeconds ?? static fn (): float => \hrtime(true) / 1e9;
        $this->hash = \hash_init('sha256');
        $this->lastHeartbeat = ($this->monotonicSeconds)();
    }

    /**
     * A stream resource reading through this meter, for the sealer.
     *
     * @return resource
     */
    public function open()
    {
        return MeteredStreamWrapper::open($this);
    }

    /**
     * One read on behalf of the sealer: up to `$length` bytes, or false once the meter has aborted.
     */
    public function read(int $length): false|string
    {
        if (null === $this->abortReason) {
            $this->beatIfDue();
        }

        return null === $this->abortReason ? $this->readChunk($length) : false;
    }

    private function readChunk(int $length): false|string
    {
        $chunk = \fread($this->source, \max(1, \min($length, UploadLimits::READ_CHUNK_BYTES)));

        if (false === $chunk) {
            $this->abortReason = self::ABORT_SOURCE_FAILED;

            return false;
        }

        if ($this->bytesRead + \strlen($chunk) > $this->declaredSize) {
            $this->abortReason = self::ABORT_OVERFLOW;

            return false;
        }

        $this->bytesRead += \strlen($chunk);
        \hash_update($this->hash, $chunk);

        if (\feof($this->source)) {
            $this->sourceEnded = true;
        }

        return $chunk;
    }

    public function ended(): bool
    {
        return $this->sourceEnded || null !== $this->abortReason;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function abortReason(): ?string
    {
        return $this->abortReason;
    }

    /**
     * What the heartbeat threw, when it could not tell whether the upload is still live — a fault of the server,
     * never the caller's, so it is rethrown rather than answered as an aborted upload.
     */
    public function heartbeatFailure(): ?Throwable
    {
        return $this->heartbeatFailure;
    }

    /**
     * The sha256 of every byte handed to the sealer, lower-case hex. Call it once, after the stream ended.
     */
    public function digest(): string
    {
        return \hash_final(\hash_copy($this->hash));
    }

    private function beatIfDue(): void
    {
        $now = ($this->monotonicSeconds)();

        if ($now - $this->lastHeartbeat < $this->heartbeatIntervalSeconds) {
            return;
        }

        $this->lastHeartbeat = $now;

        try {
            $alive = ($this->heartbeat)();
        } catch (Throwable $throwable) {
            $this->heartbeatFailure = $throwable;
            $this->abortReason = self::ABORT_HEARTBEAT_FAILED;

            return;
        }

        if (!$alive) {
            $this->abortReason = self::ABORT_LEASE_LOST;
        }
    }
}
