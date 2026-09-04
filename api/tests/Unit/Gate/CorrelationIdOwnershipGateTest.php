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
 * reads one class. The regression this catches is not that the listener changes its mind; it is that a
 * SECOND site appears, reading the caller's header and writing the attribute from somewhere the
 * behavioural test never looks. That direction has no other reader: such a change is green under
 * PHPStan, deptrac, the suites and every `php.lint.*` target, and a shape-only check on one request is
 * green over its effect by construction.
 *
 * **It matches the NAME, never the call, and that is a measurement rather than a preference.** Matching
 * call shapes — `attributes->set(…::ATTRIBUTE_KEY`, `headers->get(…::HEADER_NAME` — was measured
 * evadable in six spellings, each of them ordinary PHP: a constant aliased into the using class (which
 * {@see \Erpify\Shared\Search\Infrastructure\Http\EventListener\SearchObservabilityListener} does for
 * this very constant, so the precedent is in the tree), `attributes->add([…])`,
 * `attributes->replace([…])`, a double-quoted literal, `server->get('HTTP_X_CORRELATION_ID')` — the
 * spelling the functional tests themselves use — and `headers->all()['x-correlation-id'][0]`. Widening
 * the patterns answers those six and leaves the seventh, which is the failure mode this repository has
 * recorded twice (Caddy's `query` filter by parameter name; #389/#803). Membership has no seventh: a
 * file cannot use this identifier without naming it, whatever it then does with it.
 *
 * The two lists differ because the two names differ in kind. **The header is the listener's alone** —
 * it is the wire spelling, so every inbound read must go through it and a single owner makes any reader
 * anywhere red. The **attribute** is the in-process value three collaborators legitimately read, so
 * that list has four members and carries a third assertion: none of the readers writes a request
 * attribute at all, and the listener writes exactly one.
 *
 * **What a green proves:** in `api/src`, one file names the correlation header, four name the
 * attribute, only the listener writes it, and no reader writes any request attribute. **What it does
 * not prove:** that the listener mints — that is the behavioural pin's job, and this gate is equally
 * green over a listener stamping a constant; that a name composed at runtime is absent (`'X-' .
 * 'Correlation-Id'`, or one read from configuration), which no text rule can reach; or anything at all
 * outside `api/src`, where the value is also handled — Caddy's access log records this header like
 * every other request header, a residual carried in `PRODUCTION_SECURITY_CHECKLIST.md` §7 rather than
 * closed here.
 *
 * **Its declared cost**, so it is not met as a surprise: a collaborator with a legitimate reason to
 * name either identifier — a diagnostic route re-reading the response header, a second reader of the
 * attribute — is red until it is added to the list with its reason. That is the point rather than a
 * defect; the list is where review looks.
 *
 * @internal
 */
#[CoversNothing]
final class CorrelationIdOwnershipGateTest extends TestCase
{
    private const string LISTENER = 'Shared/Http/Infrastructure/CorrelationIdListener.php';

    /**
     * Every file in `api/src` allowed to name the correlation ATTRIBUTE, sorted by path. The three
     * besides the listener are readers: the audit factory seals it onto the row, the error responder
     * puts it in the problem body, and the search listener logs it on the `observability` channel.
     */
    private const array MAY_NAME_THE_ATTRIBUTE = [
        'Shared/Audit/Infrastructure/SealedAuditEntryFactory.php',
        'Shared/ErrorContract/Infrastructure/Http/EventListener/ExceptionResponder.php',
        'Shared/Http/Infrastructure/CorrelationIdListener.php',
        'Shared/Search/Infrastructure/Http/EventListener/SearchObservabilityListener.php',
    ];

    /** The attribute, by constant or by literal in either quoting. */
    private const string NAMES_THE_ATTRIBUTE = '/CorrelationIdListener::ATTRIBUTE_KEY|[\'"]_correlation_id[\'"]/';

    /**
     * The header, in the three spellings a reader can reach it by: the constant, the wire name (case
     * insensitively — HTTP header names are, and Symfony's bag normalises), and the CGI-ised form the
     * server bag answers to, which is how a reader bypasses the header bag entirely.
     */
    private const string NAMES_THE_HEADER = '/CorrelationIdListener::HEADER_NAME'
        . '|[\'"]X-Correlation-Id[\'"]'
        . '|HTTP_X_CORRELATION_ID/i';

    /** Any write to the request's attribute bag, through whichever of the three bag methods. */
    private const string WRITES_AN_ATTRIBUTE = '/attributes\s*->\s*(?:set|add|replace)\s*\(/';

    /** Any READ of a header bag. The listener only ever writes one, on the response. */
    private const string READS_A_HEADER = '/headers\s*->\s*(?:get|has|all)\s*\(/';

    #[Test]
    public function onlyTheListenerNamesTheCorrelationHeader(): void
    {
        $namers = \array_keys($this->filesMatching(self::NAMES_THE_HEADER));

        $this->assertSame([self::LISTENER], $namers, \sprintf(
            'Only the listener may name `X-Correlation-Id` in src. Found: %s. Every inbound read has to '
            . 'name the header somehow, so one owner is what makes any reader red — and the header is '
            . 'ignored by construction rather than validated, because a shape check cannot detect a value '
            . 'reused across unrelated requests, which is what collapses their audit rows into one '
            . 'apparent journey. A caller needing distributed tracing needs an identifier of its own.',
            \implode(', ', $namers),
        ));
    }

    #[Test]
    public function onlyReviewedFilesNameTheCorrelationAttribute(): void
    {
        $namers = \array_keys($this->filesMatching(self::NAMES_THE_ATTRIBUTE));

        $this->assertSame(self::MAY_NAME_THE_ATTRIBUTE, $namers, \sprintf(
            'The files naming the `_correlation_id` attribute have changed. Found: %s. Adding one is a '
            . 'review decision rather than a detail: a file that can name it can write it, and a second '
            . 'writer is how a caller-chosen value re-enters the audit trail with the listener untouched.',
            \implode(', ', $namers),
        ));
    }

    #[Test]
    public function onlyTheListenerWritesARequestAttribute(): void
    {
        foreach (self::MAY_NAME_THE_ATTRIBUTE as $file) {
            $writes = \preg_match_all(self::WRITES_AN_ATTRIBUTE, $this->sourceOf($file));

            $this->assertSame(self::LISTENER === $file ? 1 : 0, $writes, \sprintf(
                'The listener must write exactly one request attribute and the three readers none; `%s` '
                . 'writes %d. A reader that starts writing is the second writer this list exists to make '
                . 'visible, and it would do it while naming an attribute it already legitimately names.',
                $file,
                (int) $writes,
            ));
        }
    }

    /**
     * The header list makes any OTHER file red for reading the header; this is the half that covers the
     * listener itself, which is the one file allowed to name it. The listener never needs to read a
     * header bag — it writes one, on the response — so a read appearing inside it is the whole defect
     * returning to the file it was removed from, and the membership assertions above are blind to that
     * by construction.
     */
    #[Test]
    public function theListenerReadsNoHeaderBag(): void
    {
        $this->assertSame(
            0,
            \preg_match_all(self::READS_A_HEADER, $this->sourceOf(self::LISTENER)),
            'The listener reads a header bag. It has no reason to: it writes `X-Correlation-Id` on the '
            . 'response and reads nothing from the request, so a read here is the caller regaining the '
            . 'id the audit trail groups by — and the membership lists cannot see it, because this is '
            . 'the one file entitled to name the header.',
        );
    }

    /**
     * Comments are stripped, so the listener's own docblock — which quotes both names while explaining
     * why the header is ignored — is not counted as a use of them. That is not a hypothetical: this
     * gate's subject is a class whose documentation necessarily names what it refuses.
     *
     * @return array<string, int> relative path => number of matches, sorted by path
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

            // A `false` here is a broken pattern, not an absence of matches, and counting it as zero
            // would turn this gate green the day someone edits one of the regexes into a syntax error.
            $this->assertIsInt($matches, 'The gate pattern failed to compile, so it matched nothing.');

            if (0 === $matches) {
                continue;
            }

            $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $matches;
        }

        \ksort($found);

        return $found;
    }

    private function sourceOf(string $relativePath): string
    {
        $path = \realpath(ApiSourceFiles::root() . '/' . $relativePath);

        $this->assertIsString($path, $relativePath . ' has moved; this gate asserts nothing about it.');

        $source = \file_get_contents($path);

        $this->assertIsString($source, $relativePath . ' could not be read.');

        return PhpSource::withoutComments($source);
    }
}
