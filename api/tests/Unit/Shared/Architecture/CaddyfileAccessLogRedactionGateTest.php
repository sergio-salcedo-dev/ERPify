<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the Caddy access-log filter. The log is a sink with no owner of erasure — no compose
 * file declares a `logging:` driver, so it is the default json-file driver with no rotation and no TTL,
 * and no erasure path can reach it. What lands there is retained, and retention is the obligation this
 * gate serves: not "an attacker could read it", but "we hold a person's identifier and cannot erase it".
 *
 * Two carriers reach the entry, and they need different mechanisms because a log line records more than
 * its URI:
 *
 *  - **The query string**, dropped whole. Names are not enumerated here and must not be: Caddy's `query`
 *    filter substitutes the value of an EXACTLY NAMED parameter and has no wildcard, so an enumeration
 *    covers whatever a person remembered to write. Measured against the running stack, the enumeration
 *    that stood here contained one of nine spellings of a value.
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
 * **What a green does NOT prove.** That Caddy is configured by this file at all: `{$CADDY_SERVER_LOG_OPTIONS}`
 * expands inside the `log` block ahead of `format filter`, so a deployment that sets it to another encoder
 * replaces every rule below it without touching this repository — recorded as an open weakness rather than
 * gated, because nothing here can read a deployment's environment.
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
            '/format filter \{(?:[^{}]|\{[^{}]*\})*request>uri regexp "\[\?\]\.\*\$" "\?REDACTED"/s',
            $this->caddyfile(),
            'The Caddy access log no longer strips the query string from `request>uri`. Every value a '
            . 'client can put in a query — a person id under a name nobody listed, a single-use token, a '
            . 'filter value under any index or sub-index — is then written in clear to a sink with no '
            . 'rotation, no TTL and no owner of erasure.',
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
        $this->assertStringNotContainsString(
            'request>uri query',
            $this->caddyfile(),
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

    private function caddyfile(): string
    {
        $caddyfile = \file_get_contents(\dirname(__DIR__, 4) . '/frankenphp/Caddyfile');

        $this->assertIsString($caddyfile);

        return $caddyfile;
    }
}
