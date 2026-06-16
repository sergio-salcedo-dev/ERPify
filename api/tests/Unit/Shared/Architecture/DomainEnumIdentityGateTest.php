<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static guardrail for the domain-enum identity contract (docs/adr/domain-enums.md, D1/D3/D4). A
 * domain enum represents stable business identity; its `->value` IS the wire contract. Human-readable
 * text is the presentation layer's job (PWA i18n / `Record<Enum, label>` maps, a localizing backend
 * adapter), never the enum's — that separation is what the contract swap removed, and this gate stops
 * it from creeping back in.
 *
 * It mechanizes the statically visible half of the rule for every enum declared under a `Domain/`
 * segment (`Domain/Enum`, but also `Domain/Search` etc. — the rule is about the layer, not a folder
 * name):
 *
 *   1. No presentation accessor — a method whose name reads as display/formatting
 *      (`label`/`humanReadable`/`display`/`format`/`caption`). Business predicates and transitions
 *      (`isTerminal()`, `canTransitionTo()`) are explicitly allowed (D6); only display verbs are banned.
 *   2. No presentation attribute — `#[HumanReadable*]` or any `#[*Label*]` on a case.
 *   3. No reintroduction of a "human-readable enum" abstraction — `implements`/`use` of a
 *      `HumanReadable*` symbol (the interface/trait the swap deleted).
 *
 * Display text smuggled in by other names (a translation call, a method returning a localized string)
 * is a semantic concern invisible to a name scan and stays review-only — same division of labour as
 * the sibling architecture gates.
 *
 * @internal
 */
#[CoversNothing]
final class DomainEnumIdentityGateTest extends TestCase
{
    /**
     * A method whose NAME reads as a presentation accessor — banned on a domain enum. Matched against
     * the bare method name (never the raw file), so the stems can be anchored precisely:
     *   - exact display verbs/nouns: `label()`, `format()`, `display()`, `caption()`, `humanReadable()`;
     *   - a CamelCase display word as a name segment: `getStatusLabel()`, `displayName()`, `toCaption()`.
     * Identifiers that merely embed a stem in a different word do NOT match — `relabel`, `isLabelled`,
     * `displayOrder`, `reformat`, `formatVersion` are legitimate and stay allowed (D6 predicates too).
     */
    private const string PRESENTATION_NAME_EXACT
        = '/^(?:label|labels|caption|format|formatted|display|humanreadable)$/i';

    private const string PRESENTATION_NAME_CAMEL = '/(?:Label|Caption|Format|Display|HumanReadable)s?(?=[A-Z]|$)/';

    /** A `#[HumanReadable*]` or `#[*Label*]` attribute — presentation metadata on a case. */
    private const string PRESENTATION_ATTRIBUTE = '/#\[\s*[\w\\\]*(?:HumanReadable|Label)/i';

    /** `implements`/`use` of a `HumanReadable*` symbol — the abstraction the swap removed. */
    private const string HUMAN_READABLE_ABSTRACTION = '/\b(?:implements|use)\s+[\w\\\]*HumanReadable/i';

    public function testDomainEnumsCarryNoPresentationConcern(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $path = \str_replace('\\', '/', $file->getPathname());

            if (!\str_contains($path, '/Domain/')) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            // Scan code only: comments, docblocks and string literals are stripped first, so a
            // docblock that merely mentions "label"/"format" never trips the gate — only real code does.
            $code = $this->codeOnly($contents);

            if (1 !== \preg_match('/\benum\s+\w+/', $code)) {
                continue;
            }

            ++$scanned;

            $reasons = [];

            if ([] !== $this->presentationMethods($code)) {
                $reasons[] = \sprintf(
                    'presentation accessor(s): %s',
                    \implode(', ', $this->presentationMethods($code)),
                );
            }

            if (1 === \preg_match(self::PRESENTATION_ATTRIBUTE, $code)) {
                $reasons[] = 'presentation attribute (#[HumanReadable*] / #[*Label*])';
            }

            if (1 === \preg_match(self::HUMAN_READABLE_ABSTRACTION, $code)) {
                $reasons[] = 'HumanReadable* abstraction (implements/use)';
            }

            if ([] !== $reasons) {
                $offenders[] = \sprintf('%s — %s', $file->getFilename(), \implode('; ', $reasons));
            }
        }

        $this->assertGreaterThan(0, $scanned, 'Domain-enum identity gate scanned zero Domain enums.');
        $this->assertSame(
            [],
            $offenders,
            "A domain enum is identity, not text: its `->value` is the wire contract and human-readable\n"
            . "labels belong to the presentation layer, never the enum (docs/adr/domain-enums.md, D1/D4).\n"
            . "Move the display text to a `Record<Enum, label>` map / i18n dictionary (PWA) or a localizing\n"
            . "Application/Infrastructure adapter (backend); keep business predicates/transitions (D6):\n"
            . \implode("\n", $offenders),
        );
    }

    /**
     * Rebuild the file's source with comments, docblocks and string-literal contents removed, so the
     * name scans see only real code. Avoids the classic false positive where a docblock or a string
     * mentioning "label"/"format" trips a raw-text regex.
     */
    private function codeOnly(string $contents): string
    {
        $code = '';

        foreach (\token_get_all($contents) as $token) {
            if (!\is_array($token)) {
                $code .= $token;

                continue;
            }

            [$id, $text] = $token;

            if (T_COMMENT === $id) {
                continue;
            }

            if (T_DOC_COMMENT === $id) {
                continue;
            }

            if (T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id) {
                $code .= "''";

                continue;
            }

            $code .= $text;
        }

        return $code;
    }

    /**
     * Names of methods declared in $code whose name reads as a presentation accessor.
     *
     * @return list<string>
     */
    private function presentationMethods(string $code): array
    {
        \preg_match_all('/\bfunction\s+(\w+)\s*\(/i', $code, $matches);

        $offenders = [];

        foreach ($matches[1] as $name) {
            $isPresentation = 1 === \preg_match(self::PRESENTATION_NAME_EXACT, $name)
                || 1 === \preg_match(self::PRESENTATION_NAME_CAMEL, $name);

            if ($isPresentation) {
                $offenders[] = $name;
            }
        }

        return $offenders;
    }
}
