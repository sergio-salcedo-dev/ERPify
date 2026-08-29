<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditRedaction;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see AuditRedaction::SENTINEL} is a stored DATUM, not a label: the two erasure statements write it
 * into `audit_log.ip` / `audit_log.user_agent`, and `docs/adr/audit-activity-log.md` D4.1 asserts a
 * compliance invariant over that exact spelling. The PWA's `RedactedValue` hard-codes the same literal
 * because it renders the datum verbatim, and nothing imports across the two deployables.
 *
 * **On the API side the spelling is already falsifiable three ways** — two functional tests and
 * `features/backoffice/users/erase.feature` each spell it out, so drift there reds. On the PWA side it
 * is not. `RedactedValue.test.tsx` restates the literal too, but that pins the component against a
 * second hand-written copy in the same deployable: change the API constant and both PWA files stay
 * green, which is the direction this gate exists for. It is harmless today only because the component
 * has no caller — a fact about the current payload, not a guard, and the kind of fact that stops being
 * true the day the drawer starts rendering a redacted `ip`.
 *
 * **A sibling of {@see RedactionVocabularyParityTest} rather than a member of it.** That class pins the
 * IDENTITY AXES — which request-parameter keys two observability redactors recognise, owned by
 * `Shared/ErrorContract` and mirrored in the PWA's Sentry scrubber. This is a different vocabulary in
 * every respect that matters: a different owning module (`Shared/Audit`), a different sink (a database
 * column, not a log line or a Sentry event), a value rather than a key, and — deliberately — a
 * different literal, since the ErrorContract and Monolog sentinels are `REDACTED` while the audit one
 * carries brackets. Folding the two together would falsify that class's own stated claim ("the two name
 * the same identity axes") and invite exactly the collapse the two spellings avoid.
 *
 * Read as TEXT, comments blanked first: this component's docblock names `[REDACTED]` twice, so a
 * containment check over the raw file would pass over a drifted render on the strength of its own prose.
 * What is extracted is the rendered TEXT NODE, so a sentinel that stops being a literal — moved behind a
 * JSX expression, wrapped in another element — reds rather than being read past. That direction fails
 * closed on purpose: a value this gate cannot see is a value it cannot pin.
 *
 * What a green proves, and only this: the datum the API writes is the datum this component renders. It
 * says nothing about any other PWA copy of the literal, nothing about whether the component is reached,
 * and nothing about which rows the two erasure statements are entitled to write it over — that licence
 * is each statement's own and is argued at the constant.
 *
 * @internal
 */
#[CoversNothing]
final class AuditRedactionSentinelParityTest extends TestCase
{
    private const string RENDER_SITE = 'pwa/src/context/backoffice/audit/infrastructure/ui/RedactedValue.tsx';

    #[Test]
    public function theRedactionSentinelIsTheSameOnBothDeployables(): void
    {
        $this->assertSame(
            AuditRedaction::SENTINEL,
            $this->renderedSentinel(),
            \sprintf(
                'The API redaction sentinel and the literal `%s` renders have drifted apart. The value '
                . 'is the datum itself — the audit trail stores it to keep a deliberately cleared field '
                . 'distinguishable from one that was never captured — so a read side rendering a '
                . 'different spelling misreports what the row says happened.',
                self::RENDER_SITE,
            ),
        );
    }

    private function renderedSentinel(): string
    {
        $source = $this->withoutComments($this->read($this->repoRoot() . '/' . self::RENDER_SITE));

        $rendered = \preg_match_all('#>\s*([^<>{}]+?)\s*</span>#s', $source, $matches);

        $this->assertSame(1, $rendered, \sprintf(
            'Expected exactly one literal text node inside a `<span>` in %s, found %d. Zero means the '
            . 'sentinel is no longer rendered as a bare literal — moved behind a JSX expression, or '
            . 'wrapped in another element — which this gate refuses to read past, because a value it '
            . 'cannot see is a value it cannot pin. More than one means it can no longer tell which node '
            . 'is the datum.',
            self::RENDER_SITE,
            $rendered,
        ));

        return $matches[1][0];
    }

    /**
     * Blanks TypeScript comments before anything is extracted. Load-bearing here rather than defensive:
     * the component's own docblock spells the sentinel out twice, so a gate reading the raw file could
     * satisfy itself from prose while the rendered literal had drifted.
     */
    private function withoutComments(string $source): string
    {
        $stripped = \preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        $this->assertIsString($stripped);

        return $stripped;
    }

    /**
     * The PWA tree sits outside the `./api` build context, so in the container it arrives only through the
     * read-only `./` bind mount at `/app/repo` declared in `compose.dev.yaml`. Missing it is a failure, not
     * a skip: a parity gate that passes when it cannot see one of the two sites reports an agreement it
     * never checked.
     */
    private function repoRoot(): string
    {
        $apiRoot = \dirname(__DIR__, 3);

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
        $this->assertFileExists($path, \sprintf(
            'The PWA site of the redaction sentinel is missing: %s. Re-derive this gate against wherever '
            . 'it moved rather than deleting it.',
            $path,
        ));

        $contents = \file_get_contents($path);

        $this->assertIsString($contents, \sprintf('Could not read %s', $path));

        return $contents;
    }
}
