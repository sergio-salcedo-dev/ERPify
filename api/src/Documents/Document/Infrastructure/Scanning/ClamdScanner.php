<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Scanning;

use Erpify\Documents\Document\Application\Scanning\WriteCallbackStream;
use Erpify\Documents\Document\Domain\Scanning\MalwareScanner;
use Erpify\Documents\Document\Domain\Scanning\ScanInterrupted;
use Erpify\Documents\Document\Domain\Scanning\ScannerUnavailable;
use Erpify\Documents\Document\Domain\Scanning\ScanVerdict;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * ClamAV's `clamd` over TCP, speaking its `INSTREAM` protocol: the command, then length-prefixed chunks of the
 * plaintext, a zero-length chunk, and one NUL-terminated reply.
 *
 * **Every answer is classified, and only two are clean-or-infected.** `stream: OK` is clean. `stream: <name> FOUND`
 * is infected, EXCEPT a name starting `Heuristics.Limits.Exceeded` — what `AlertExceedsMax yes` makes clamd answer
 * when a file, archive, recursion or file-count limit stopped it from examining the whole stream — and
 * `INSTREAM size limit exceeded. ERROR` (the `StreamMaxLength` ceiling): both are {@see ScanVerdict::LimitExceeded}.
 * Anything else, an `ERROR` included, is no verdict. A limit answer is never clean, which is the property
 * `api/clamav/clamd.conf` and `DocumentScannerLimitsGateTest` hold.
 *
 * Writer-driven: the producer writes the plaintext into a sink whose every write is one frame. The socket's read
 * timeout is {@see ScanPolicy::READ_TIMEOUT_SECONDS}, below the heartbeat interval, so waiting for the verdict is a
 * poll and the caller's `$keepAlive` runs between polls; the wait ends at clamd's own `MaxScanTime` plus a margin.
 * The plaintext travels on the internal Docker network and is not encrypted in transit.
 */
#[AsAlias(MalwareScanner::class)]
final readonly class ClamdScanner implements MalwareScanner
{
    private const string LIMIT_SIGNATURE_PREFIX = 'Heuristics.Limits.Exceeded';

    /** Seconds past clamd's own `MaxScanTime` the wait for a verdict is allowed. */
    private const int WAIT_MARGIN_SECONDS = 30;

    public function __construct(
        #[Autowire(param: 'documents.scanner.address')]
        private string $address,
        private int $readTimeoutSeconds = ScanPolicy::READ_TIMEOUT_SECONDS,
    ) {
    }

    #[Override]
    public function probe(): bool
    {
        $socket = ClamdSocket::open($this->address, ScanPolicy::PROBE_TIMEOUT_SECONDS);

        if (!$socket instanceof ClamdSocket) {
            return false;
        }

        try {
            $socket->setReadTimeout(ScanPolicy::PROBE_TIMEOUT_SECONDS);

            return $socket->send("zPING\0")
                && 'PONG' === $socket->answer(static fn (): bool => false, ScanPolicy::PROBE_TIMEOUT_SECONDS);
        } finally {
            $socket->close();
        }
    }

    #[Override]
    public function scan(callable $producer, callable $keepAlive): ScanVerdict
    {
        $socket = ClamdSocket::open($this->address, ScanPolicy::PROBE_TIMEOUT_SECONDS);

        if (!$socket instanceof ClamdSocket || !$socket->send("zINSTREAM\0")) {
            $socket?->close();

            throw ScannerUnavailable::create();
        }

        $socket->setReadTimeout($this->readTimeoutSeconds);

        try {
            $early = $this->stream($socket, $producer);

            if ($early instanceof ScanVerdict) {
                return $early;
            }

            if (!$socket->sendChunk('')) {
                // The scanner stopped listening before the terminator: if it said why, that answer is the verdict.
                return $this->pendingLimitAnswer($socket) ?? throw ScanInterrupted::create();
            }

            $answer = $socket->answer(
                $keepAlive,
                \intdiv(ScanPolicy::CLAMD_MAX_SCAN_TIME_MILLISECONDS, 1000) + self::WAIT_MARGIN_SECONDS,
            );
        } finally {
            $socket->close();
        }

        return $this->classify($answer ?? '') ?? throw ScanInterrupted::create();
    }

    /**
     * Hands the producer a sink of frames. A producer that fails after the scanner stopped listening may have been
     * told why — a stream past the ceiling is answered, then closed — so that answer is read before the failure
     * propagates and, when it is a limit answer, is the verdict. Any other failure leaves the producer's own
     * exception unchanged.
     *
     * @param callable(resource): void $producer
     *
     * @throws Throwable whatever the producer throws
     */
    private function stream(ClamdSocket $socket, callable $producer): ?ScanVerdict
    {
        $sendFailed = false;
        $sink = WriteCallbackStream::open(
            static function (string $bytes) use ($socket, &$sendFailed): bool {
                $sent = '' === $bytes || $socket->sendChunk($bytes);
                $sendFailed = $sendFailed || !$sent;

                return $sent;
            },
        );

        try {
            $producer($sink);

            return null;
        } catch (Throwable $throwable) {
            return ($sendFailed ? $this->pendingLimitAnswer($socket) : null) ?? throw $throwable;
        } finally {
            \fclose($sink);
        }
    }

    /**
     * The answer a scanner that stopped listening left behind, when it is a limit answer: a stream past the ceiling is
     * answered and then closed, so a failed send is not the end of what the scanner had to say.
     */
    private function pendingLimitAnswer(ClamdSocket $socket): ?ScanVerdict
    {
        $answer = $socket->answer(static fn (): bool => true, 2);

        return null !== $answer && ScanVerdict::LimitExceeded === $this->classify($answer)
            ? ScanVerdict::LimitExceeded
            : null;
    }

    private function classify(string $answer): ?ScanVerdict
    {
        $answer = \trim($answer);
        $signature = 1 === \preg_match('/^stream: (?<signature>.+) FOUND$/D', $answer, $matches)
            ? $matches['signature']
            : null;

        return match (true) {
            'stream: OK' === $answer => ScanVerdict::Clean,
            \str_starts_with($answer, 'INSTREAM size limit exceeded') => ScanVerdict::LimitExceeded,
            null === $signature => null,
            \str_starts_with($signature, self::LIMIT_SIGNATURE_PREFIX) => ScanVerdict::LimitExceeded,
            default => ScanVerdict::Infected,
        };
    }
}
