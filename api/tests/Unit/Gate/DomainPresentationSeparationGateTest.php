<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static guardrail for the domain–presentation separation rule
 * (docs/adr/domain-presentation-separation.md; generalizes docs/adr/domain-enums.md, D4). Presentation
 * — display text, formatting, i18n — never lives in an inner-layer type: no `Domain/` type (enum, value
 * object, entity, domain service) and no `Application/` DTO/mapper carries labels, UI formatting, i18n
 * strings, or label-by-reflection. Readable text is the presentation layer's job, keyed by the identity
 * value (PWA `Record<Key, label>` / i18n dictionary, or a localizing backend adapter).
 *
 * It mechanizes the statically visible half of the rule:
 *
 *   1. No presentation accessor — a method whose name reads as display/formatting
 *      (`label`/`humanReadable`/`display`/`format`/`caption`). Business predicates and transitions
 *      (`isTerminal()`, `canTransitionTo()`) are explicitly allowed (DPS4); only display verbs are banned.
 *   2. No presentation attribute — `#[HumanReadable*]` or any `#[*Label*]` on a member.
 *   3. No reintroduction of a "human-readable" abstraction — `implements`/`use` of a `HumanReadable*`
 *      symbol (the interface/trait the enum swap deleted).
 *
 * `Domain/` is held to all three; `Application/` to the attribute + abstraction vectors only — a method
 * name like `format()` is FP-prone there (queries, identifiers), so that half stays review-only (DPS5).
 * Display text smuggled in by a neutral name (a translation call, a method returning a localized string)
 * is invisible to a name scan and stays review-only too — same division of labour as the sibling
 * architecture gates.
 *
 * @internal
 */
#[CoversNothing]
final class DomainPresentationSeparationGateTest extends TestCase
{
    /**
     * A method whose NAME reads as a presentation accessor — banned in the inner layers. Matched against
     * the bare method name (never the raw file), so the stems can be anchored precisely:
     *   - exact display verbs/nouns: `label()`, `format()`, `display()`, `caption()`, `humanReadable()`;
     *   - a CamelCase display word as a name segment: `getStatusLabel()`, `displayName()`, `toCaption()`.
     * Identifiers that merely embed a stem in a different word do NOT match — `relabel`, `isLabelled`,
     * `displayOrder`, `reformat`, `formatVersion` are legitimate and stay allowed (DPS4 predicates too).
     */
    private const string PRESENTATION_NAME_EXACT
        = '/^(?:label|labels|caption|format|formatted|display|humanreadable)$/i';

    private const string PRESENTATION_NAME_CAMEL = '/(?:Label|Caption|Format|Display|HumanReadable)s?(?=[A-Z]|$)/';

    /** A `#[HumanReadable*]` or `#[*Label*]` attribute — presentation metadata on a member. */
    private const string PRESENTATION_ATTRIBUTE = '/#\[\s*[\w\\\]*(?:HumanReadable|Label)/i';

    /** `implements`/`use` of a `HumanReadable*` symbol — the abstraction the enum swap removed. */
    private const string HUMAN_READABLE_ABSTRACTION = '/\b(?:implements|use)\s+[\w\\\]*HumanReadable/i';

    public function testDomainTypesCarryNoPresentationConcern(): void
    {
        [$offenders, $scanned] = $this->scanLayer('/Domain/', checkMethodNames: true);

        $this->assertGreaterThan(0, $scanned, 'Domain–presentation gate scanned zero Domain types.');
        $this->assertSame(
            [],
            $offenders,
            "A Domain type is identity + business rules, not text: display text, formatting and i18n\n"
            . "belong to the presentation layer, never to a Domain enum/value object/entity\n"
            . "(docs/adr/domain-presentation-separation.md, DPS1/DPS4). Move it to a `Record<Key, label>`\n"
            . "map / i18n dictionary (PWA) or a localizing Application/Infrastructure adapter (backend);\n"
            . "keep business predicates/transitions:\n"
            . \implode("\n", $offenders),
        );
    }

    public function testApplicationDtosAndMappersCarryNoPresentationMetadata(): void
    {
        [$offenders, $scanned] = $this->scanLayer('/Application/', checkMethodNames: false);

        $this->assertGreaterThan(0, $scanned, 'Domain–presentation gate scanned zero Application types.');
        $this->assertSame(
            [],
            $offenders,
            "An Application DTO/mapper translates identity into a transport shape, never into human prose\n"
            . "(docs/adr/domain-presentation-separation.md, DPS2). Presentation metadata\n"
            . "(`#[HumanReadable*]` / `#[*Label*]`) and any `HumanReadable*` abstraction belong to the\n"
            . "presentation layer; a localizing backend catalog lives in Infrastructure, keyed by identity:\n"
            . \implode("\n", $offenders),
        );
    }

    /**
     * Scan every PHP file whose path contains $segment for the statically visible presentation vectors.
     *
     * @return array{0: list<string>, 1: int} the offender descriptions and the number of files scanned
     */
    private function scanLayer(string $segment, bool $checkMethodNames): array
    {
        $offenders = [];
        $scanned = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $path = \str_replace('\\', '/', $file->getPathname());

            if (!\str_contains($path, $segment)) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            ++$scanned;

            // Scan code only: comments, docblocks and string literals are stripped first, so a
            // docblock that merely mentions "label"/"format" never trips the gate — only real code does.
            $code = $this->codeOnly($contents);

            $reasons = [];

            $methods = $checkMethodNames ? $this->presentationMethods($code) : [];

            if ([] !== $methods) {
                $reasons[] = \sprintf('presentation accessor(s): %s', \implode(', ', $methods));
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

        return [$offenders, $scanned];
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
