<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Shared\Search\Application\Http\SearchQuery;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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
 * Both families reach the entry twice over, because a log line records more than the URI: the referring
 * document's URL rides along in a header, and for a same-origin API call that document is the screen the
 * ids live on. Redacting the URI alone leaves the same values in clear on the same line.
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
        yield 'next (a URL carried inside a value)' => [
            'next',
            'an expired session redirects to /login?next=<the whole audit URL>, so the ids that screen '
            . 'holds arrive under a name no axis list contains. This filter cannot read inside a value, '
            . 'so the whole parameter goes.',
        ];
    }

    /**
     * The header half of the same entry. Caddy logs request headers by default and censors only the
     * credential-bearing ones, so every `replace` above is undone on its own line by a `Referer` naming the
     * document the request came from: for a same-origin API call that document is the audit screen, whose
     * URL carries the very ids the URI filter just blanked.
     *
     * Asserted as `delete` rather than as a per-parameter filter because the filter that understands query
     * parameters acts on a URI field and a header is not one — and because a partial rule here would fail
     * the way the index enumeration can, leaking whatever the list forgot.
     */
    #[Test]
    public function itDropsTheRefererHeader(): void
    {
        $this->assertMatchesRegularExpression(
            '/format filter \{(?:[^{}]|\{[^{}]*\})*request>headers>Referer delete/s',
            $this->caddyfile(),
            'The Caddy access log no longer drops the `Referer` header. The referring URL is logged in '
            . 'full, so any screen holding a person id in its query string puts that id back into the '
            . 'same log entry whose `uri` this filter redacts.',
        );
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
     * The bound that matters, because a log records what the CALLER sent. Caddy writes this entry before
     * Symfony validates anything — a request that ends in 422 or 404 is logged just the same — so the
     * enumeration has to cover every index the API is willing to accept, from any client, not only the
     * indices this UI happens to emit.
     *
     * Measured before it was widened: `filters[12][value]=<uuid>` reached the access log in clear on the
     * same line where `filters[8][value]` read `REDACTED`.
     */
    #[Test]
    public function itCoversEveryIndexTheApiAccepts(): void
    {
        $covered = \count($this->redactedFilterIndices());

        $this->assertGreaterThanOrEqual(
            SearchQuery::MAX_FILTERS,
            $covered,
            \sprintf(
                'The API accepts %d filters and the Caddy access log only redacts filters[0..%d][value]. '
                . 'Every index in between reaches the log in clear, whatever the PWA happens to emit — the '
                . 'entry is written before validation, so a 422 is logged like any other request. Widen the '
                . 'enumeration in api/frankenphp/Caddyfile to cover index %d.',
                SearchQuery::MAX_FILTERS,
                $covered - 1,
                SearchQuery::MAX_FILTERS - 1,
            ),
        );
    }

    /**
     * The second bound, and the weaker one: the Caddyfile is a Caddy file gated by a PHP test, and the
     * producer of the indices is TypeScript. A builder that outgrew even the API's own cap would be a
     * contract break upstream of this file, and this is where it surfaces.
     *
     * The builders are discovered from the tree rather than listed here, because a list is exactly as
     * stale as the day someone adds a screen: a fifth builder with ten axes would serialize `filters[9]`
     * past an enumeration this gate had already declared sufficient, and nothing would say so.
     *
     * Counting `filters.push(` is a textual heuristic over straight-line builders, which is what every one
     * of them is today. A builder that pushed in a loop, spread an array, or delegated to a helper would
     * count low and this gate would not notice — that direction rests on review.
     */
    #[Test]
    public function itCoversEveryIndexThePwaCanEmit(): void
    {
        $covered = \count($this->redactedFilterIndices());

        foreach ($this->criteriaBuilders() as $builder) {
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
     * Every PWA criteria builder, discovered from the tree instead of enumerated. Naming carries the
     * contract: a builder that serializes through `buildSearchParams` is a `*SearchCriteria.ts` under the
     * app router. Discovery is what makes the assertion above a gate rather than a snapshot — a hand-kept
     * list cannot fail on the screen nobody remembered to add to it.
     *
     * @return list<string>
     */
    private function criteriaBuilders(): array
    {
        $root = $this->repoRoot();
        $builders = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/pwa/src/app', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($tree as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();

            if (\str_ends_with($path, 'SearchCriteria.ts')) {
                $builders[] = \substr($path, \strlen($root) + 1);
            }
        }

        \sort($builders);

        $this->assertNotEmpty(
            $builders,
            'No `*SearchCriteria.ts` was found under pwa/src/app, so the assertion that the Caddyfile '
            . 'covers every index the PWA can emit would pass over an empty set. Either the naming '
            . 'convention changed — re-derive this — or the PWA tree is not reachable.',
        );

        return $builders;
    }

    /**
     * The repository root, which differs by how the suite is invoked: with the whole checkout present it is
     * the parent of `api/`, while inside the dev container `/app` holds only `api/`, `public/` and the
     * mounts, so the rest arrives through the read-only root bind mount declared in `compose.dev.yaml`.
     */
    private function repoRoot(): string
    {
        $apiRoot = \dirname(__DIR__, 4);

        foreach ([\dirname($apiRoot), \dirname($apiRoot) . '/repo'] as $candidate) {
            if (\is_dir($candidate . '/pwa/src/app')) {
                return $candidate;
            }
        }

        $this->fail(
            'The PWA tree is not reachable, so this gate cannot check anything. Inside the container it '
            . 'comes from the read-only `./` bind mount at /app/repo declared in compose.dev.yaml — '
            . 'restore it rather than relaxing this failure into a skip.',
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
