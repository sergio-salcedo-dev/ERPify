<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the Caddy access log: where it is written, how long it is kept, and what it filters. The
 * log is a sink with no owner of erasure — its own file, rolled daily and deleted after 14 days, which bounds
 * it, but no erasure path reaches it. What lands there is retained until then, and retention is the
 * obligation this gate serves: not "an attacker could read it", but "we hold a person's identifier and cannot
 * erase it".
 *
 * Two carriers reach the entry, and they need different mechanisms because a log line records more than
 * its URI:
 *
 *  - **The query string**, dropped whole, from the first `?` **or `%3f`**. Names are not enumerated here
 *    and must not be: Caddy's `query` filter substitutes the value of an EXACTLY NAMED parameter and has no
 *    wildcard, so an enumeration covers whatever a person remembered to write — measured against the
 *    running stack, the enumeration that stood here contained one of nine spellings of a value. The encoded
 *    delimiter is in the pattern because anchoring on a literal `?` was itself measured letting
 *    `/api/v1/health%3Ffoo=<value>` through whole: the strip's reach would otherwise be a property of the
 *    caller's spelling.
 *  - **The `Referer` header**, dropped whole. For a same-origin API call the referring document is the
 *    screen the ids live on, so an unfiltered header reproduces in clear exactly what the `uri` filter
 *    blanked, on the same entry. A filter over a URI field cannot reach a header, which is why this is a
 *    second rule rather than a wider first one.
 *
 * **This gate reads the file; it cannot run Caddy.** A rule that parses but does nothing is invisible
 * here, which is the hole its own predecessor named and nobody closed for as long as it stood. The effect
 * is asserted by `AccessLogQueryContainmentGateTest`, which sends real requests through Caddy and reads
 * the line that was written. Neither replaces the other: this one fails when the rule is *deleted* from
 * the file, that one fails when the rule is *ineffective*, and only this one runs without a stack.
 *
 * **What a green does NOT prove.** That no other logger writes the same request: `{$CADDY_GLOBAL_OPTIONS}` and
 * `{$CADDY_EXTRA_CONFIG}` expand outside the site's `log` block and can still add one from a deployment's
 * environment — recorded as an open weakness rather than gated, because nothing here can read that
 * environment. Inside the block nothing expands, and that much is gated below. Nor that the roll ever ran:
 * the file says when Caddy should roll and prune, never that it did.
 *
 * @internal
 */
#[CoversNothing]
final class CaddyfileAccessLogRedactionGateTest extends TestCase
{
    /**
     * The query is dropped by pattern rather than by name, so no parameter can outgrow it: a new axis, a
     * filter index past any cap, the `in` operator's array spelling and a name nobody listed are all one
     * case here. That is the property, and it is why a `query` filter reappearing in this block is a
     * regression rather than an addition — see the case below.
     */
    #[Test]
    public function itStripsTheWholeQueryStringFromTheLoggedUri(): void
    {
        $this->assertMatchesRegularExpression(
            '/format filter \{(?:[^{}]|\{[^{}]*\})*request>uri regexp '
                . '"\(\?i\)\(\[\?\]\|%3f\|%253f\)\.\*\$" "\?REDACTED"/s',
            $this->caddyfile(),
            'The Caddy access log no longer strips the query string from `request>uri`. Every value a '
            . 'client can put in a query — a person id under a name nobody listed, a single-use token, a '
            . 'filter value under any index or sub-index — is then written in clear to a sink kept for 14 '
            . 'days with no owner of erasure.',
        );
    }

    /**
     * A `query` filter here would mean somebody went back to enumerating names, and an enumeration beside
     * a whole-query strip is worse than either alone: it reads as coverage while the strip is what is
     * actually doing the work, and if the strip is later removed in favour of "the names are covered", the
     * axis reopens silently. One mechanism, refused explicitly.
     */
    #[Test]
    public function itDoesNotEnumerateQueryParameterNames(): void
    {
        // Scoped to the `format filter` block rather than the whole file: the comment above the block
        // discusses the `query` filter at length, and a gate that reds on its own rationale being written
        // down teaches the next reader to delete the rationale.
        //
        // Matched by REGEX rather than a literal substring: Caddyfile whitespace between two directive
        // tokens is not significant, so a reintroduced enumeration written with a different amount of it
        // would parse identically to Caddy while evading a literal `assertStringNotContainsString`.
        $this->assertDoesNotMatchRegularExpression(
            '/request>uri\s+query\b/',
            $this->formatFilterBlock(),
            'The Caddy access-log filter enumerates query parameter names again. Caddy matches a '
            . 'parameter name exactly and has no wildcard, so an enumeration covers what someone '
            . 'remembered rather than what a client can send — measured, the enumeration this replaced '
            . 'contained one of nine spellings of a value. Drop the query whole instead.',
        );
    }

    /**
     * Dropped whole rather than filtered parameter by parameter, and the reason is a grammar limit: the
     * filter that acts on parameters acts on a URI field, and a header is not one.
     */
    #[Test]
    public function itDropsTheRefererHeader(): void
    {
        $this->assertMatchesRegularExpression(
            '/format filter \{(?:[^{}]|\{[^{}]*\})*request>headers>Referer delete/s',
            $this->caddyfile(),
            'The Caddy access log no longer drops the `Referer` header. The referring URL is logged in '
            . 'full, so any screen holding a person id in its query string puts that id back into the '
            . 'same log entry whose `uri` this filter strips.',
        );
    }

    /**
     * The access log's own file, rolled every day and pruned after 14 days. On the container's stdio it shared
     * one json-file budget with the application's channels, where any producer could evict another's history
     * and nothing expired by age. `roll_at` is the half that makes the TTL real: size rolling never rolls an
     * idle file, and pruning runs only when a file is rolled. Compared whole, so a value widened in place
     * reds as surely as a directive dropped.
     */
    #[Test]
    public function itWritesTheAccessLogToItsOwnDailyRolledFile(): void
    {
        $matched = \preg_match('/output file (\S+) \{([^{}]*)\}/', $this->logBlock(), $output);

        $this->assertSame(1, $matched, 'The access log no longer declares an `output file` of its own.');
        $this->assertSame('/var/log/caddy/access.log', $output[1]);
        $this->assertSame(
            ['roll_size 32MiB', 'roll_keep 20', 'roll_keep_for 336h', 'roll_at 00:00'],
            \array_values(\array_filter(\array_map(
                static fn (string $line): string => (string) \preg_replace('/\s+/', ' ', \trim($line)),
                \explode("\n", $output[2]),
            ))),
            'The access log\'s roll changed. Its retention is a decision recorded in '
            . 'PRODUCTION_SECURITY_CHECKLIST.md §7, and without `roll_at` an idle deployment never rolls the '
            . 'active file, so nothing in it ever expires.',
        );
    }

    /**
     * An environment expansion inside the block is how a deployment used to point this log back at stderr, or
     * swap the encoder ahead of `format filter`, with every rule here still green. Nothing in the block may be
     * decided outside the file.
     */
    #[Test]
    public function itExpandsNoEnvironmentVariableInsideTheLogBlock(): void
    {
        $this->assertStringNotContainsString(
            '{$',
            $this->logBlock(),
            'The site\'s `log` block expands an environment variable again, so a deployment can change where '
            . 'the access log goes or what it filters without this file changing.',
        );
    }

    /**
     * The site's `log { … }` block, nested blocks included. Fails loudly if it is gone, for the reason
     * {@see formatFilterBlock()} does.
     */
    private function logBlock(): string
    {
        $matched = \preg_match('/\blog \{(?:[^{}]|\{[^{}]*\})*\}/s', $this->caddyfile(), $block);

        $this->assertSame(1, $matched, 'The Caddyfile declares no `log` block at all.');

        return $block[0];
    }

    /**
     * The `format filter { … }` block alone, so a rule is read where it acts rather than anywhere in the
     * file. Fails loudly if the block is gone: an empty string would satisfy every "does not contain"
     * assertion here.
     */
    private function formatFilterBlock(): string
    {
        $matched = \preg_match('/format filter \{(?:[^{}]|\{[^{}]*\})*\}/s', $this->caddyfile(), $block);

        $this->assertSame(1, $matched, 'The Caddyfile declares no `format filter` block at all.');

        return $block[0];
    }

    private function caddyfile(): string
    {
        $caddyfile = \file_get_contents(\dirname(__DIR__, 3) . '/frankenphp/Caddyfile');

        $this->assertIsString($caddyfile);

        return $caddyfile;
    }
}
