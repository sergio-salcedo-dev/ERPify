<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\PhpSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over who may name a request's correlation id.
 *
 * The value stamped on `_correlation_id` becomes the `correlation_id` column of every `audit_log` row
 * written during the request, and the back office groups the forensic trail by that column. So whoever
 * can choose it can choose how the trail reads — and no check applied to a single request can tell a
 * reused id from a fresh one: a shape test proves the value looks like a UUIDv7, never that its bearer
 * minted it. The property that holds is structural: nothing outside the process names it.
 *
 * **Why a static gate beside the behavioural ones.** `CorrelationIdListenerTest` proves the listener
 * ignores an inbound header, and it is the instrument that actually demonstrates the property — but it
 * reads one class. The way this regresses is not that the listener changes its mind; it is that a
 * SECOND site appears, reading the caller's header and writing the attribute from somewhere the
 * behavioural test never looks. That direction has no other reader: the change would be green under
 * PHPStan, deptrac, the suites and every `php.lint.*` target, exactly as the shape-only check it
 * replaces was green for months.
 *
 * The two assertions are the two halves of the same invariant and neither implies the other. A file can
 * read the inbound header without writing the attribute (feeding it to a log line, a response body, a
 * projection — the value is caller-chosen wherever it lands), and a file can write the attribute
 * without reading the header (deriving it from a query string, a cookie, a payload field).
 *
 * **What a green proves:** in `api/src`, one file writes the correlation attribute and none reads the
 * inbound header by name. Not that the listener mints — that is the behavioural pin's job. It is blind
 * to a read spelled dynamically (`$request->headers->get($name)` with the name computed), to anything
 * outside `api/src` (a Caddy directive copying the header onto another one would be invisible here),
 * and to a caller-chosen value arriving through any header this gate does not name.
 *
 * @internal
 */
#[CoversNothing]
final class CorrelationIdOwnershipGateTest extends TestCase
{
    private const string LISTENER = 'Shared/Http/Infrastructure/CorrelationIdListener.php';

    /**
     * A write from OUTSIDE the listener: the attribute can only be named there by the qualified constant or
     * by the literal. A bare `self::ATTRIBUTE_KEY` is deliberately absent — in another class it is that
     * class's own key, and `RateLimitSnapshot` declares one, so admitting it would report a false writer.
     */
    private const string FOREIGN_WRITE = '/attributes\s*->\s*set\s*\(\s*(?:'
        . '[A-Za-z\\\]*CorrelationIdListener::ATTRIBUTE_KEY'
        . "|'_correlation_id'"
        . ')/';

    /** The listener's own write, which is the one spelling no other class can use to mean this attribute. */
    private const string OWN_WRITE = '/attributes\\s*->\\s*set\\s*\\(\\s*self::ATTRIBUTE_KEY/';

    /**
     * A READ of the inbound header: `get`, `has` or `all` against a header bag, named by the constant or
     * by the literal in any case (HTTP header names are case-insensitive and Symfony's bag normalises,
     * so `x-correlation-id` reaches the same value). `set` is deliberately absent — the listener's own
     * response write is a `set`, and it is the one legitimate mention of the name.
     */
    private const string READS_THE_INBOUND_HEADER = '/headers\s*->\s*(?:get|has|all)\s*\(\s*(?:'
        . '[A-Za-z\\\]*CorrelationIdListener::HEADER_NAME'
        . '|self::HEADER_NAME'
        . "|'X-Correlation-Id'"
        . ')/i';

    #[Test]
    public function srcWritesTheCorrelationAttributeInExactlyOnePlace(): void
    {
        $foreign = $this->filesMatching(self::FOREIGN_WRITE);
        unset($foreign[self::LISTENER]);

        $this->assertSame([], \array_keys($foreign), \sprintf(
            'Only the listener may write the `_correlation_id` request attribute. Found: %s. A second writer '
            . 'is how a caller-chosen value re-enters the audit trail without the listener changing at all — '
            . 'and it is the shape no behavioural test of the listener can see.',
            \implode(', ', \array_keys($foreign)),
        ));

        $this->assertSame(
            1,
            \preg_match_all(self::OWN_WRITE, PhpSource::withoutComments($this->listenerSource())),
            'The listener must write the correlation attribute exactly once. None means this gate is '
            . 'asserting over a class that no longer mints; more than one means two writes that can drift, '
            . 'and only one of them is the mint.',
        );
    }

    #[Test]
    public function srcNeverReadsTheInboundCorrelationHeader(): void
    {
        $readers = $this->filesMatching(self::READS_THE_INBOUND_HEADER);

        $this->assertSame([], \array_keys($readers), \sprintf(
            'No class in src may read an inbound `X-Correlation-Id`. Found: %s. The header is dropped by '
            . 'construction rather than validated, because a shape check cannot detect a value reused '
            . 'across unrelated requests — which is what collapses their audit rows into one apparent '
            . 'journey. A caller that needs distributed tracing needs an identifier of its own, not '
            . 'authority over this one.',
            \implode(', ', \array_keys($readers)),
        ));
    }

    private function listenerSource(): string
    {
        $path = \realpath(ApiSourceFiles::root() . '/' . self::LISTENER);

        $this->assertIsString($path, 'The listener this gate reads has moved; the gate is asserting nothing.');

        $source = \file_get_contents($path);

        $this->assertIsString($source, 'The listener this gate reads could not be read.');

        return $source;
    }

    /**
     * Comments are stripped first, so the listener's own docblock — which quotes the header name while
     * explaining why it is ignored — is not counted as a use of it. That is not a hypothetical: this
     * gate's subject is a class whose documentation necessarily names what it refuses.
     *
     * @return array<string, int> relative path => number of matches, in tree order
     */
    private function filesMatching(string $pattern): array
    {
        $found = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            if (!\is_string($source)) {
                continue;
            }

            $matches = \preg_match_all($pattern, PhpSource::withoutComments($source));

            // A `false` here is a broken pattern, not an absence of matches, and counting it as zero would
            // turn this gate green on the day someone edits one of the two regexes above into a syntax error.
            $this->assertIsInt($matches, 'The gate pattern failed to compile, so it matched nothing.');

            if (0 === $matches) {
                continue;
            }

            $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $matches;
        }

        \ksort($found);

        return $found;
    }
}
