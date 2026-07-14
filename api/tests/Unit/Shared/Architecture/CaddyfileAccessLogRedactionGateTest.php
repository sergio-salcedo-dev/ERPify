<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the Caddy access-log filter: the single-use invitation/reset links travel as
 * `?token=<id>.<secret>`, so the `token` query parameter (like Mercure's `authorization`) must be redacted
 * before the request URI lands in the access log — a token in a log outlives its one-hour TTL and is
 * readable by anyone with log access. Caddy config is not exercised by the PHP test kernel, so this gate
 * pins the filter textually: removing either `replace` fails the build instead of silently un-redacting.
 *
 * @internal
 */
#[CoversNothing]
final class CaddyfileAccessLogRedactionGateTest extends TestCase
{
    public function testAccessLogRedactsSecretBearingQueryParameters(): void
    {
        $caddyfile = \file_get_contents(\dirname(__DIR__, 4) . '/frankenphp/Caddyfile');

        $this->assertIsString($caddyfile);
        $this->assertMatchesRegularExpression(
            '/format filter \{\s*request>uri query \{[^}]*replace authorization REDACTED/s',
            $caddyfile,
            'The Caddy access log no longer redacts the `authorization` query parameter.',
        );
        $this->assertMatchesRegularExpression(
            '/format filter \{\s*request>uri query \{[^}]*replace token REDACTED/s',
            $caddyfile,
            'The Caddy access log no longer redacts the `token` query parameter '
            . '(the single-use invitation/reset secret).',
        );
    }
}
