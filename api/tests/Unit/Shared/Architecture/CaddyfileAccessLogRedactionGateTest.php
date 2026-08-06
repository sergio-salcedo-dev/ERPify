<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over the Caddy access-log filter. The log is a sink with no owner of erasure — no compose
 * file declares a `logging:` driver, so stderr lands in the default json-file driver with no rotation and
 * no TTL, and no erasure path can reach it. Two families of value must therefore never enter it:
 *
 *  - Secrets: the invitation/reset links travel as `?token=<id>.<secret>`, and Mercure sets
 *    `?authorization=`. Either one in a log outlives its TTL and is readable by anyone with log access.
 *  - Person ids: the audit screen holds `actorId`/`resourceId` in the document URL, and fires the same
 *    identities at the API under the generic `filters[N][value]` grammar. A person id here survives the
 *    erasure the application confirmed to the subject.
 *
 * Caddy config is not exercised by the PHP test kernel, so this gate pins the filter textually: removing
 * a `replace` fails the build instead of silently un-redacting.
 *
 * What it does NOT prove, so nobody reads a green as more than it is: that Caddy applies the filter at
 * all. The `query` filter acts on parameters it finds, and a typo'd parameter name is a no-op the text
 * cannot distinguish from a working rule. That half is verified by request against a running stack.
 *
 * @internal
 */
#[CoversNothing]
final class CaddyfileAccessLogRedactionGateTest extends TestCase
{
    /**
     * Every criteria builder in the PWA, each of which serializes through `buildSearchParams` into the
     * `filters[N][value]` grammar. The Caddyfile enumerates one index per possible filter, so the widest
     * builder here decides how wide the enumeration has to be.
     */
    private const array CRITERIA_BUILDERS = [
        'pwa/src/app/backoffice/audit/_lib/auditSearchCriteria.ts',
        'pwa/src/app/backoffice/banks/_lib/banksSearchCriteria.ts',
        'pwa/src/app/backoffice/bank-accounts/_lib/bankAccountsSearchCriteria.ts',
        'pwa/src/app/backoffice/users/_lib/usersSearchCriteria.ts',
    ];

    #[Test]
    #[DataProvider('provideItRedactsTheNamedQueryParameterCases')]
    public function itRedactsTheNamedQueryParameter(string $parameter, string $consequence): void
    {
        $this->assertMatchesRegularExpression(
            '/format filter \{\s*request>uri query \{[^}]*replace ' . \preg_quote($parameter, '/')
                . ' REDACTED/s',
            $this->caddyfile(),
            \sprintf('The Caddy access log no longer redacts `%s`: %s', $parameter, $consequence),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItRedactsTheNamedQueryParameterCases(): iterable
    {
        yield 'authorization (Mercure)' => [
            'authorization',
            'a Mercure subscriber JWT would land in the access log.',
        ];
        yield 'token (single-use invitation/reset secret)' => [
            'token',
            'the single-use invitation/reset secret would land in the access log, outliving its TTL.',
        ];
        yield 'actorId (audit document URL)' => [
            'actorId',
            'the audit screen puts the acting person id in the document URL.',
        ];
        yield 'resourceId (audit document URL)' => [
            'resourceId',
            'the audit screen puts the subject person id in the document URL when the resource is a person.',
        ];
        yield 'correlationId (audit document URL)' => [
            'correlationId',
            'a request-correlation id reconstructs a session from the log.',
        ];
    }

    /**
     * The positional grammar has no wildcard, so each index is enumerated by hand and a gap would
     * un-redact one axis while its neighbours stayed covered — invisible to a spot check of the file.
     */
    #[Test]
    public function itRedactsTheFilterValueIndicesWithoutAGap(): void
    {
        $indices = $this->redactedFilterIndices();

        $this->assertNotEmpty($indices, 'The Caddy access log redacts no `filters[N][value]` at all.');
        $this->assertSame(
            \range(0, \count($indices) - 1),
            $indices,
            'The redacted `filters[N][value]` indices are not contiguous from 0. A gap un-redacts one '
            . 'filter axis; which axis lands on which index depends on how many earlier ones the user '
            . 'filled in, so the leak is intermittent rather than absent.',
        );
    }

    /**
     * The one thing nothing else ties together: the Caddyfile is a Caddy file gated by a PHP test, and
     * the producer of the indices is TypeScript. A tenth filter axis would serialize to
     * `filters[9][value]`, which the enumeration does not cover, and every other gate would stay green.
     *
     * Counting `filters.push(` is a textual heuristic over straight-line builders, which is what all four
     * are today. A builder that pushed in a loop, spread an array, or delegated to a helper would count
     * low and this gate would not notice — that direction rests on review.
     */
    #[Test]
    public function itCoversEveryIndexThePwaCanEmit(): void
    {
        $covered = \count($this->redactedFilterIndices());

        foreach (self::CRITERIA_BUILDERS as $builder) {
            $source = $this->readRepoFile($builder);
            $emitted = \substr_count($source, 'filters.push(');

            $this->assertLessThanOrEqual(
                $covered,
                $emitted,
                \sprintf(
                    '%s can emit %d filters, but the Caddy access log only redacts filters[0..%d][value]. '
                    . 'Widen the enumeration in api/frankenphp/Caddyfile to cover index %d, or the value '
                    . 'of the widest filter — which for this screen is a person id — lands in the log.',
                    $builder,
                    $emitted,
                    $covered - 1,
                    $emitted - 1,
                ),
            );
        }
    }

    /**
     * Staleness guard for the assertion above: it reasons about `filters[N][value]`, so it is only worth
     * anything while that is the shape the PWA actually serializes.
     */
    #[Test]
    public function itIsStillReasoningAboutTheGrammarThePwaSerializes(): void
    {
        $this->assertStringContainsString(
            '[value]',
            $this->readRepoFile('pwa/src/context/shared/search/infrastructure/buildSearchParams.ts'),
            'The PWA no longer serializes filters as `filters[N][value]`, so redacting that key name '
            . 'protects nothing. Re-derive the Caddyfile entries from the new grammar.',
        );
    }

    /**
     * @return list<int>
     */
    private function redactedFilterIndices(): array
    {
        \preg_match_all(
            '/replace filters\[(\d+)\]\[value\] REDACTED/',
            $this->caddyfile(),
            $matches,
        );

        $indices = \array_map(\intval(...), $matches[1]);
        \sort($indices);

        return $indices;
    }

    private function caddyfile(): string
    {
        $caddyfile = \file_get_contents(\dirname(__DIR__, 4) . '/frankenphp/Caddyfile');

        $this->assertIsString($caddyfile);

        return $caddyfile;
    }

    /**
     * Reads a path relative to the repository root, which differs by how the suite is invoked: with the
     * whole checkout present it is the parent of `api/`, while inside the dev container `/app` holds only
     * `api/`, `public/` and the mounts, so it arrives through the read-only root bind mount declared in
     * `compose.dev.yaml`.
     *
     * An unreachable file FAILS rather than skipping, for the reason the sibling gates spell out: a check
     * that quietly does nothing when its input is absent reports the same green as a real pass.
     */
    private function readRepoFile(string $relativePath): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            $path = $candidate . '/' . $relativePath;

            if (!\is_file($path)) {
                continue;
            }

            $source = \file_get_contents($path);

            if (false !== $source) {
                return $source;
            }
        }

        $this->fail(\sprintf(
            '%s is not reachable, so this gate cannot check anything. Inside the container the PWA tree '
            . 'comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — '
            . 'restore it rather than relaxing this failure into a skip.',
            $relativePath,
        ));
    }
}
