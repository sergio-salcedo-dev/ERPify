<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\Exception\InvalidCursor;
use JsonException;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encodes and verifies keyset cursors. Wire format (K3):
 *
 *   `base64url(json{v, dir, values, fp})` + `.` + `HMAC-SHA256(body)` truncated
 *   to 32 hex (128 bits) — exactly one `.` separator.
 *
 * Unlike the legacy {@see \Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory}
 * there is NO zlib: a cursor is a small, bounded token, and compression buys
 * nothing but an extra failure mode. The HMAC pattern (secret via
 * `%kernel.secret%`, `hash_equals`, `.` separator) is the one proven piece kept.
 *
 * Decoding validates in the intrinsic-first DAG order (AR10):
 *   signature → version → payload → fingerprint.
 * The length cap is enforced BEFORE any HMAC work (NFR1: never hash an
 * unbounded input). The fingerprint check is a DEFERRED integrity hook: the
 * caller passes the expected fingerprint as a plain string and the codec
 * compares — it NEVER imports or knows {@see QueryExecutionTrace}. Wiring the
 * trace to the expected fingerprint is the engine's job in PR2; coupling it here
 * would be premature and break the AR16 sequence.
 *
 * The `dir` field is an integrity binding (K2/AR21): compared against the
 * expected direction, never consulted to decide navigation. A mismatch is a
 * payload-integrity failure.
 */
final readonly class CursorCodec
{
    public const int CURRENT_VERSION = 1;

    /** Max wire length accepted before any HMAC work — a DoS guard (NFR1). */
    private const int MAX_ENCODED_LENGTH = 512;

    private const string HMAC_ALGO = 'sha256';

    /** 128 bits = 32 hex characters. */
    private const int SIGNATURE_LENGTH = 32;

    private const string SEPARATOR = '.';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function encode(Cursor $cursor): string
    {
        // Fixed key order: the body is signed and round-tripped, so its bytes
        // must be deterministic for a given cursor.
        $json = \json_encode(
            ['v' => $cursor->v, 'dir' => $cursor->dir, 'values' => $cursor->values, 'fp' => $cursor->fp],
            JSON_THROW_ON_ERROR,
        );

        $body = \sodium_bin2base64($json, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return $body . self::SEPARATOR . $this->sign($body);
    }

    public function decode(string $encoded, string $expectedFingerprint, string $expectedDir): Cursor
    {
        $payload = $this->decodePayload($this->authenticBody($encoded));

        // Version first: a cursor that does not carry our version marker is an
        // unsupported version, reported before its structure is scrutinised.
        if (self::CURRENT_VERSION !== ($payload['v'] ?? null)) {
            throw InvalidCursor::version();
        }

        $dir = $payload['dir'] ?? null;
        $values = $payload['values'] ?? null;
        $fp = $payload['fp'] ?? null;

        if (!\is_string($dir) || !\is_array($values) || !\is_string($fp)) {
            throw InvalidCursor::payload();
        }

        // `dir` is an integrity binding — compared, never used to decide navigation (AR21).
        if (!\hash_equals($expectedDir, $dir)) {
            throw InvalidCursor::payload();
        }

        // Fingerprint is the only extrinsic check — always last (AR10).
        if (!\hash_equals($expectedFingerprint, $fp)) {
            throw InvalidCursor::fingerprint();
        }

        /** @var array<string, mixed> $values */
        return new Cursor(self::CURRENT_VERSION, $dir, $values, $fp);
    }

    /**
     * Length cap → split → HMAC. Returns the verified body or throws
     * {@see InvalidCursor::signature()} — no payload byte is decoded until the
     * signature is proven, so a forged cursor never reaches the JSON parser.
     */
    private function authenticBody(string $encoded): string
    {
        // why: over-length and a missing `.` separator are intrinsic FORMAT
        // failures — the token has no authenticable body yet, so they are NOT
        // signature failures (no body to verify) and map to `payload`. This keeps
        // the four pinned causes (signature|version|payload|fingerprint) exact —
        // no invented "too-long" cause — and respects the intrinsic-first order
        // (length/structure precede crypto). Decision sealed for the PR3
        // `invalid_cursor_count{cause}` metric; do not reopen.
        if (\strlen($encoded) > self::MAX_ENCODED_LENGTH) {
            throw InvalidCursor::payload();
        }

        $separator = \strrpos($encoded, self::SEPARATOR);

        if (false === $separator) {
            throw InvalidCursor::payload();
        }

        $body = \substr($encoded, 0, $separator);
        $signature = \substr($encoded, $separator + 1);

        if (!\hash_equals($this->sign($body), $signature)) {
            throw InvalidCursor::signature();
        }

        return $body;
    }

    /**
     * Decodes the authentic body to its raw payload map. Only base64/JSON
     * structural failures are caught here (→ payload): a body that will not
     * parse cannot have its version read. Field-level validation lives in
     * {@see decode} so it runs in the intrinsic-first order.
     *
     * @return array<array-key, mixed>
     */
    private function decodePayload(string $body): array
    {
        try {
            $json = \sodium_base642bin($body, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (SodiumException $sodiumException) {
            throw InvalidCursor::payload($sodiumException);
        }

        try {
            $payload = \json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw InvalidCursor::payload($jsonException);
        }

        if (!\is_array($payload)) {
            throw InvalidCursor::payload();
        }

        return $payload;
    }

    private function sign(string $body): string
    {
        return \substr(\hash_hmac(self::HMAC_ALGO, $body, $this->secret), 0, self::SIGNATURE_LENGTH);
    }
}
