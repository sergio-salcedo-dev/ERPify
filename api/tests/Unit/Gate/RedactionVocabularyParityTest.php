<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two deployables hold the same redaction vocabulary and neither imports the other: {@see RequestUriRedaction}
 * writes the API's per-error log line, and the PWA's `redaction.ts` writes the Sentry event. Each is a
 * different sink, and an axis one of them stops recognising is a person's identifier reaching a store with
 * a size bound but no TTL and no erasure path.
 *
 * Both sites are read as TEXT on purpose — one is PHP and the other TypeScript, so there is no import that
 * could make them agree by construction.
 *
 * **The access log is deliberately not a third site.** It carries no vocabulary to compare: Caddy drops the
 * query string whole rather than naming what is sensitive in it, so there is nothing there that can drift
 * from these two. That is also what closed a hole this class used to record as measured and open — the edge
 * matched a parameter name literally, with none of the padding-stripping or repeated decoding the other two
 * perform, so `?actorId%00=` reached the access log in clear while both other sinks redacted it.
 * `CaddyfileAccessLogRedactionGateTest` and `AccessLogQueryContainmentGateTest` hold that sink now.
 *
 * What a green proves, and only this: the two name the same identity axes. It does NOT prove they redact the
 * same way, and it does not compare the two search-value patterns, the two denylists, or the decode and
 * nesting bounds, all of which are mirrored by hand.
 *
 * @internal
 */
#[CoversNothing]
final class RedactionVocabularyParityTest extends TestCase
{
    #[Test]
    public function theIdentityAxesAreTheSameOnBothDeployables(): void
    {
        $declaration = $this->read(
            $this->repoRoot() . '/pwa/src/context/shared/observability/domain/redaction.ts',
        );

        $matched = \preg_match('/IDENTITY_AXES = \[([^\]]*)\]/', $declaration, $matches);

        $this->assertSame(1, $matched, 'IDENTITY_AXES is no longer declared as an array literal.');

        \preg_match_all('/"([^"]+)"/', $matches[1], $axes);

        $drifted = 'The PWA identity axes and the API IDENTITY_KEYS have drifted apart. An axis only one '
            . 'side recognises is a person id kept out of one sink and let into the other.';

        $this->assertSame(RequestUriRedaction::IDENTITY_KEYS, $axes[1], $drifted);
    }

    /**
     * The PWA tree sits outside the `./api` build context, so in the container it arrives only through the
     * read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`. Missing it is a failure, not
     * a skip: a parity gate that passes when it cannot see one of the two sites reports an agreement it
     * never checked.
     */
    private function repoRoot(): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_dir($candidate . '/pwa/src')) {
                return $candidate;
            }
        }

        $this->fail(
            'The PWA tree is not reachable, so this gate cannot check anything. Inside the container it '
            . 'comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — '
            . 'restore it rather than relaxing this failure into a skip.',
        );
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path, \sprintf('A site of the redaction vocabulary is missing: %s', $path));

        $contents = \file_get_contents($path);

        $this->assertIsString($contents, \sprintf('Could not read %s', $path));

        return $contents;
    }
}
