<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Shared\ErrorContract\Application\RequestUriRedaction;
use Erpify\Tests\Unit\Shared\ErrorContract\Application\RequestUriRedactionTest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three files hold the same redaction vocabulary and none imports another: `api/frankenphp/Caddyfile` writes
 * the access log, {@see RequestUriRedaction} writes the per-error log line, and the PWA's `redaction.ts`
 * writes the Sentry event. Each is a different sink, and an axis one of them stops recognising is a person's
 * identifier reaching a store with no rotation, no TTL and no erasure path.
 *
 * The sibling {@see CaddyfileAccessLogRedactionGateTest} gates the edge's index RANGE against what the API
 * accepts and what the PWA can emit. This gates the vocabulary itself, which that one takes as given: its
 * list of named parameters is hand-written, so the API and the PWA could name different identity axes and
 * every assertion there would still pass.
 *
 * Both sites are read as TEXT on purpose — one is a Caddy config and the other TypeScript, so there is no
 * import that could make them agree by construction.
 *
 * What a green proves, and only this: the three name the same identity axes, and the case claiming to step
 * past the edge still does. It does NOT prove they redact the same way. They measurably do not — the edge
 * matches a parameter name literally, with none of the padding-stripping or repeated decoding the other two
 * perform, so `?actorId%00=` reaches the access log in clear while both other sinks redact it. Nor does it
 * compare the two search-value patterns, the two denylists, or the decode and nesting bounds, all of which
 * are mirrored by hand.
 *
 * @internal
 */
#[CoversNothing]
final class RedactionVocabularyParityTest extends TestCase
{
    private const string STEPS_PAST_THE_EDGE = 'search value axis at an index beyond what the edge enumerates';

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
     * The edge's list of named parameters is hand-written — in its own gate's data provider as much as in
     * the Caddyfile — so a fourth axis added to BOTH deployables would leave the access log naming three and
     * every other assertion green. Deriving the expectation from `IDENTITY_KEYS` is what closes that.
     */
    #[Test]
    public function everyIdentityAxisIsAlsoRedactedAtTheEdge(): void
    {
        $caddyfile = $this->read(\dirname(__DIR__, 4) . '/frankenphp/Caddyfile');

        foreach (RequestUriRedaction::IDENTITY_KEYS as $axis) {
            $missing = \sprintf(
                'The Caddy access log names no `replace` for the identity axis `%s`, which both deployables '
                    . 'redact. The edge writes a sink of its own; an axis only it misses still reaches disk.',
                $axis,
            );

            $this->assertMatchesRegularExpression(
                '/replace ' . \preg_quote($axis, '/') . ' REDACTED/i',
                $caddyfile,
                $missing,
            );
        }
    }

    /**
     * The edge has no wildcard, so its reach is whatever it enumerates; the enum's pattern has no ceiling,
     * which is the deliberate difference between them. The case that exists to prove that difference has to
     * use an index the edge does NOT cover — otherwise it keeps passing while testing none of it, which is
     * exactly what happened when the enumeration grew from `filters[0..8]` to `filters[0..19]` underneath a
     * case pinned at index 12.
     */
    #[Test]
    public function theCaseThatStepsPastTheEdgeUsesAnIndexTheEdgeDoesNotEnumerate(): void
    {
        \preg_match_all(
            '/replace filters\[(\d+)\]\[value\] REDACTED/',
            $this->read(\dirname(__DIR__, 4) . '/frankenphp/Caddyfile'),
            $enumerated,
        );

        $this->assertNotEmpty($enumerated[1], 'The Caddyfile no longer enumerates the search value axis.');

        $highestAtTheEdge = \max(\array_map(\intval(...), $enumerated[1]));
        $indexUnderTest = $this->indexTheSuiteStepsPastTheEdgeWith();

        $this->assertGreaterThan($highestAtTheEdge, $indexUnderTest, \sprintf(
            'The edge now enumerates up to filters[%d][value], so index %d no longer steps past it and '
                . 'the case named "%s" is asserting a difference that closed underneath it.',
            $highestAtTheEdge,
            $indexUnderTest,
            self::STEPS_PAST_THE_EDGE,
        ));
    }

    private function indexTheSuiteStepsPastTheEdgeWith(): int
    {
        $cases = \iterator_to_array(
            RequestUriRedactionTest::provideItReplacesTheSensitiveValueAndKeepsItsKeyCases(),
            true,
        );
        $requestUri = $cases[self::STEPS_PAST_THE_EDGE][0] ?? null;

        $gone = \sprintf('The case "%s" is gone from its provider.', self::STEPS_PAST_THE_EDGE);

        $this->assertIsString($requestUri, $gone);

        $matched = \preg_match('/filters%5B(\d+)%5D/', $requestUri, $matches);

        $this->assertSame(1, $matched, 'That case no longer carries a numeric filter index.');

        return (int) $matches[1];
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
