<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The sink invariant, asserted as an EFFECT: no value a client puts in a query string survives into the
 * access log, whatever it is spelled — supported or not, valid or not, scalar or array, enumerated index
 * or not, answered 2xx or 401.
 *
 * This is the gate the two that came before it could not be. `CaddyfileAccessLogRedactionGateTest` and
 * `RedactionVocabularyParityTest` both read the config and assert the SHAPE of an enumeration; both were
 * green for the whole life of a leak that put a person's email address in this log in clear, because the
 * `in` operator spells its value `filters[0][value][]` and a name-matching filter with no wildcard cannot
 * reach a name it was not given. A control over an effect needs an instrument that observes the effect.
 *
 * It sends real requests through Caddy rather than through the kernel, because the subject is Caddy's log
 * filter and the kernel never runs it.
 *
 * **What a green does NOT prove.** Read this as the specification of the blind spots:
 *
 *   - It observes the dev/test DESTINATION. `compose.dev.yaml` points the access log at a file so this can
 *     read it; production leaves `CADDY_SERVER_LOG_OPTIONS` unset and keeps stderr. What is under test —
 *     the `format filter` block — is byte-identical on both, and the variable does not touch it. The one
 *     thing that direction hides is the hole the variable itself is: it expands ahead of `format filter`,
 *     so a deployment setting it to another encoder replaces the filtering entirely, and no test here or
 *     anywhere else would notice.
 *   - It says nothing about the URL PATH. `/api/v1/backoffice/users/<uuid>` carries a person's id and is a
 *     separately recorded, separately accepted residual.
 *   - It says nothing about any other sink: the application log, Sentry, or the Next.js container's own
 *     request line each answer this vocabulary through their own mechanism and their own tests.
 *   - The spelling set below is HAND-WRITTEN, so a spelling nobody imagined is invisible to it. That is the
 *     hole its companion closes from the other side: `SearchOperatorSurfaceGateTest` derives its universe
 *     from the field mappings in the tree rather than from a list a person maintains.
 *   - It proves the line carries no query value, never that the line is useful.
 *
 * @internal
 */
#[CoversNothing]
final class AccessLogQueryContainmentGateTest extends TestCase
{
    /**
     * A request that is REJECTED is logged exactly like one that is served — the entry is written before
     * the application validates anything — so every spelling here is probed unauthenticated on purpose,
     * and one case covers the served path as well because production's `fingers_crossed` handler discards
     * non-error records and a 2xx therefore exists in this log and nowhere else.
     */
    private const string REJECTED_PATH = '/api/v1/backoffice/users';

    private const string SERVED_PATH = '/api/v1/health';

    private const int FLUSH_TIMEOUT_MICROSECONDS = 5_000_000;

    private const int FLUSH_POLL_MICROSECONDS = 100_000;

    #[Test]
    #[DataProvider('provideNoQueryValueSurvivesIntoTheAccessLogCases')]
    public function noQueryValueSurvivesIntoTheAccessLog(string $path, string $queryTemplate): void
    {
        $sentinel = 'sentinel' . \bin2hex(\random_bytes(10));
        $offset = $this->accessLogSize();

        $this->get($path . '?' . \str_replace('{sentinel}', $sentinel, $queryTemplate));

        $written = $this->accessLogWrittenSince($offset, $path);

        $this->assertStringNotContainsString($sentinel, $written, \sprintf(
            "A query value reached the access log in clear.\nSpelling: %s\nThe log has no owner of erasure "
                . '(no compose file declares a `logging:` driver, so it is the default json-file driver with '
                . 'neither rotation nor TTL), so whatever lands there outlives the erasure the application '
                . 'confirmed to the subject.',
            \str_replace('{sentinel}', '<value>', $queryTemplate),
        ));
    }

    /**
     * Each case is a way of putting a value in a query string. The point of the set is that only the first
     * one is covered by a name-matching filter: every other line is a name that no enumeration contains,
     * which is why an enumeration is the wrong instrument rather than an incomplete one.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideNoQueryValueSurvivesIntoTheAccessLogCases(): iterable
    {
        yield 'scalar value, the only spelling an enumeration reaches' => [
            self::REJECTED_PATH,
            'filters[0][field]=email&filters[0][operator]=eq&filters[0][value]={sentinel}',
        ];

        yield 'the array form the in operator emits' => [
            self::REJECTED_PATH,
            'filters[0][field]=email&filters[0][operator]=in&filters[0][value][]={sentinel}',
        ];

        yield 'the numeric sub-index form of the same list' => [
            self::REJECTED_PATH,
            'filters[0][field]=email&filters[0][operator]=in&filters[0][value][0]={sentinel}',
        ];

        yield 'one filter index past what the API accepts' => [
            self::REJECTED_PATH,
            'filters[20][field]=email&filters[20][operator]=eq&filters[20][value]={sentinel}',
        ];

        yield 'an index far past any cap' => [
            self::REJECTED_PATH,
            'filters[999][field]=email&filters[999][operator]=eq&filters[999][value]={sentinel}',
        ];

        yield 'a sub-index past any list length the API accepts' => [
            self::REJECTED_PATH,
            'filters[0][field]=email&filters[0][operator]=in&filters[0][value][999]={sentinel}',
        ];

        yield 'the same key double-encoded, which no decoding filter here can unwrap' => [
            self::REJECTED_PATH,
            'filters%255B0%255D%255Bvalue%255D={sentinel}',
        ];

        yield 'a parameter name nobody listed' => [
            self::REJECTED_PATH,
            'somethingNobodyEnumerated={sentinel}',
        ];

        yield 'a served 2xx, which lives in this log and in no other' => [
            self::SERVED_PATH,
            'filters[0][field]=email&filters[0][operator]=in&filters[0][value][]={sentinel}',
        ];
    }

    /**
     * The assertion above passes trivially over an empty read, and an empty read is exactly what a broken
     * probe produces — a request that never reached Caddy, a log that stopped being written, a path
     * pointing at nothing. So the arrival of the line is asserted separately rather than assumed, on the
     * same mechanism the other case depends on.
     */
    #[Test]
    public function theProbeReachesTheLogAtAll(): void
    {
        $offset = $this->accessLogSize();

        $this->get(self::SERVED_PATH . '?probe=arrival');

        $this->assertStringContainsString(
            self::SERVED_PATH,
            $this->accessLogWrittenSince($offset, self::SERVED_PATH),
            'The probe produced no access-log entry, so every containment assertion in this class would '
                . 'have passed over an empty string. Check that the stack is up and that '
                . '`CADDY_SERVER_LOG_OPTIONS` still points the access log at the file this reads.',
        );
    }

    private function get(string $uri): void
    {
        $handle = \curl_init('https://localhost' . $uri);

        if (false === $handle) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        // Verification is off because the dev certificate is self-signed and the subject of this test is
        // what Caddy WRITES, not what it presents. The request never leaves the container.
        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = \curl_exec($handle);
        $error = \curl_error($handle);

        if (false === $body) {
            throw new RuntimeException(\sprintf('The probe could not reach Caddy: %s', $error));
        }
    }

    private function accessLogPath(): string
    {
        $path = \dirname(__DIR__, 4) . '/var/log/caddy-access.log';

        if (!\is_readable($path)) {
            // Deliberately fatal rather than skipped: a gate that disappears when its instrument does is a
            // gate that reports success for the one state it exists to catch.
            throw new RuntimeException(\sprintf(
                'The access log is not readable at %s. `compose.dev.yaml` sets CADDY_SERVER_LOG_OPTIONS so '
                    . 'Caddy writes it there; without it this gate can observe nothing.',
                $path,
            ));
        }

        return $path;
    }

    private function accessLogSize(): int
    {
        \clearstatcache(true, $this->accessLogPath());

        return (int) \filesize($this->accessLogPath());
    }

    /**
     * Caddy writes the entry after the response is sent, so a read taken the instant `curl` returns can
     * legitimately find nothing. Polls until the line naming the probed path arrives, then returns
     * everything written since the offset — the poll is what keeps a slow write from reading as a pass.
     */
    private function accessLogWrittenSince(int $offset, string $path): string
    {
        $deadline = self::FLUSH_TIMEOUT_MICROSECONDS;
        $written = '';

        while ($deadline > 0) {
            \clearstatcache(true, $this->accessLogPath());
            $handle = \fopen($this->accessLogPath(), 'r');

            if (false === $handle) {
                throw new RuntimeException('Could not open the access log.');
            }

            \fseek($handle, $offset);
            $written = (string) \stream_get_contents($handle);
            \fclose($handle);

            if (\str_contains($written, $path)) {
                return $written;
            }

            \usleep(self::FLUSH_POLL_MICROSECONDS);
            $deadline -= self::FLUSH_POLL_MICROSECONDS;
        }

        return $written;
    }
}
