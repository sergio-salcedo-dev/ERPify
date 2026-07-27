<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static gate over `api/.audit-resource-types`, the declared classification of every audit `resource_type`
 * as person-denoting or not.
 *
 * It exists because GDPR erasure of the resource axis is assigned to the bounded context that owns the
 * person (`docs/adr/audit-activity-log.md` D4), and a distributed obligation is only safe if the moment it
 * arises is impossible to pass silently. The defect this repo actually shipped was exactly that: a new
 * `resource_type = 'User'` row was introduced with nobody erasing it, so the subject's real id survived
 * their own erasure beside their pseudonym. The gate turns "somebody must remember" into "the build stops
 * until somebody decides".
 *
 * Two directions, both mechanical:
 *
 *   - **Completeness** — every type reaching `AuditResource::of()` or declared as a route's
 *     `_audit_resource_type` default must be classified here, whether the type is written at the call as a
 *     literal or held in a same-class constant. The constant form is not an exotic case to tolerate: it is
 *     the form the person type itself uses, so a regex that only saw literals would be blind to the one
 *     type this gate exists for.
 *   - **Wiring** — every `person` line must name a file that holds an `AuditResourceAnonymiser` property
 *     *and calls `anonymise()` on it*, so "declared a person, nobody erases it" fails too. Matching is done
 *     on comment-stripped source: a docblock naming the collaborator is prose, not wiring, and must not be
 *     able to satisfy the check on its own.
 *
 * What it deliberately cannot do: judge the classification. Calling `Contact` a non-person passes. That is
 * a review decision, and the gate's job is to force it to be *made*, in a diffable file, at the moment the
 * type is introduced. Two source forms also stay out of reach — a type assembled at runtime from request
 * attributes ({@see \Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor}, whose inputs
 * the route-default check covers instead) and a constant imported from another class. Runtime reachability
 * of the anonymiser call is proven by the owning context's Behat scenario, not here.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureGateTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../../../.audit-resource-types';

    private const string SOURCE_ROOT = __DIR__ . '/../../../../src';

    private const string ANONYMISER = 'AuditResourceAnonymiser';

    private const string NON_PERSON = 'non-person';

    #[Test]
    public function everyResourceTypeInUseIsClassified(): void
    {
        $declared = \array_keys($this->registry());
        $inUse = $this->resourceTypesInSource();

        $unclassified = \array_values(\array_diff($inUse, $declared));

        $this->assertSame([], $unclassified, \sprintf(
            'These audit resource types are written by the code but not classified in .audit-resource-types: %s. '
            . 'Declare each as `non-person` or `person :: <erasure use case>` — a person-denoting type with no '
            . 'erasure leaves the subject named in the trail after their own erasure.',
            \implode(', ', $unclassified),
        ));
    }

    #[Test]
    public function everyPersonTypeNamesAFileThatAnonymisesIt(): void
    {
        foreach ($this->registry() as $type => $erasurePath) {
            if (null === $erasurePath) {
                continue;
            }

            $absolute = self::SOURCE_ROOT . '/../' . $erasurePath;
            $this->assertFileExists($absolute, \sprintf(
                'The erasure use case declared for the person type "%s" does not exist: %s',
                $type,
                $erasurePath,
            ));

            $source = $this->codeWithoutComments((string) \file_get_contents($absolute));

            $held = \preg_match(\sprintf('/%s\s+\$(\w+)/', self::ANONYMISER), $source, $property);
            $this->assertSame(1, $held, \sprintf(
                '%s is declared as erasing the person type "%s" but holds no %s property.',
                $erasurePath,
                $type,
                self::ANONYMISER,
            ));
            $this->assertStringContainsString(\sprintf('$this->%s->anonymise(', $property[1]), $source, \sprintf(
                '%s holds a %s but never calls anonymise() on it, so the person type "%s" is declared as '
                . 'erased while nothing erases it.',
                $erasurePath,
                self::ANONYMISER,
                $type,
            ));
            $this->assertStringContainsString(\sprintf("'%s'", $type), $source, \sprintf(
                '%s does not carry the "%s" type literal, so it cannot be what erases it.',
                $erasurePath,
                $type,
            ));
        }
    }

    #[Test]
    public function theRegistryDeclaresNoTypeThatNothingWrites(): void
    {
        // Looser than the completeness check on purpose: it accepts the quoted literal anywhere in `src`,
        // including a call the completeness regexes cannot attribute to a call site. That looseness is also
        // its limit — for a type whose only literal is its own erasure declaration, this check is satisfied
        // by the consumer rather than by any writer, so it cannot report that type as stale.
        $sources = \implode("\n", \array_map(
            static fn (string $file): string => (string) \file_get_contents($file),
            $this->sourceFiles(),
        ));

        $stale = [];

        foreach (\array_keys($this->registry()) as $type) {
            if (!\str_contains($sources, \sprintf("'%s'", $type))) {
                $stale[] = $type;
            }
        }

        $this->assertSame([], $stale, \sprintf(
            'These types are classified but no longer written anywhere: %s. Remove them so the registry '
            . 'stays a live inventory rather than a graveyard.',
            \implode(', ', $stale),
        ));
    }

    /**
     * @return array<string, string|null> type => erasure use-case path, or null when non-person
     */
    private function registry(): array
    {
        $lines = \file(self::REGISTRY, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);

        $registry = [];

        foreach ($lines as $line) {
            $line = \trim($line);

            if ('' === $line) {
                continue;
            }

            if (\str_starts_with($line, '#')) {
                continue;
            }

            $parts = \array_map(trim(...), \explode('=>', $line, 2));
            $this->assertCount(2, $parts, \sprintf(
                'Malformed registry line (expected `Type => classification`): %s',
                $line,
            ));
            [$type, $classification] = $parts;

            // Last-wins would let a duplicate line silently downgrade a person type to non-person, and the
            // wiring check skips non-person types — so the shadowed line would take the erasure with it.
            $this->assertArrayNotHasKey($type, $registry, \sprintf(
                'Duplicate registry line for "%s": the later classification silently shadows the earlier one.',
                $type,
            ));

            if (self::NON_PERSON === $classification) {
                $registry[$type] = null;

                continue;
            }

            // Anything unrecognised is rejected rather than read as non-person. A silent fall-through would
            // turn a capitalisation slip (`Person`) into "nobody erases this", which is the whole failure
            // mode the registry exists to make impossible.
            $declaresErasure = \preg_match('/^person\s*::\s*(\S.*)$/', $classification, $person);
            $this->assertSame(1, $declaresErasure, \sprintf(
                'Unrecognised classification for "%s": "%s". Write exactly `%s`, or `person :: <path of the '
                . 'erasure use case>`.',
                $type,
                $classification,
                self::NON_PERSON,
            ));

            $registry[$type] = \trim($person[1]);
        }

        return $registry;
    }

    /**
     * @return list<string>
     */
    private function resourceTypesInSource(): array
    {
        $types = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) \file_get_contents($file);

            \preg_match_all("/AuditResource::of\\(\\s*'([^']+)'/", $source, $constructed);
            \preg_match_all("/'_audit_resource_type'\\s*=>\\s*'([^']+)'/", $source, $routed);

            $types = [...$types, ...$constructed[1], ...$routed[1], ...$this->typesHeldInConstants($source)];
        }

        $types = \array_values(\array_unique($types));
        \sort($types);

        return $types;
    }

    /**
     * Resolves `AuditResource::of(self::SOME_TYPE, …)` back to the literal the constant holds, within the
     * same file. Both non-literal call sites in this codebase take that form — including the person type,
     * whose literal deliberately lives in the owning context rather than at the call.
     *
     * @return list<string>
     */
    private function typesHeldInConstants(string $source): array
    {
        \preg_match_all('/AuditResource::of\(\s*(?:self|static)::(\w+)/', $source, $references);

        $types = [];

        foreach ($references[1] as $constant) {
            $declaration = \sprintf("/const\\s+string\\s+%s\\s*=\\s*'([^']+)'/", \preg_quote($constant, '/'));

            if (1 === \preg_match($declaration, $source, $literal) && isset($literal[1])) {
                $types[] = $literal[1];
            }
        }

        return $types;
    }

    /**
     * Strips comments so the wiring check reads code only — a docblock that names the anonymiser describes
     * an intention, and an intention must not be able to stand in for the call.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $directory = new RecursiveDirectoryIterator(self::SOURCE_ROOT, FilesystemIterator::SKIP_DOTS);

        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
