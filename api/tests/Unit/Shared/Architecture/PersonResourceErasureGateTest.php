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
 *   - **Completeness** — every type literal reaching `AuditResource::of('<T>', …)` or declared as a route's
 *     `_audit_resource_type` default must be classified here.
 *   - **Wiring** — every `person` line must name a file that actually references
 *     `AuditResourceAnonymiser` and the type literal, so "declared a person, nobody erases it" fails too.
 *
 * What it deliberately cannot do: judge the classification. Calling `Contact` a non-person passes. That is
 * a review decision, and the gate's job is to force it to be *made*, in a diffable file, at the moment the
 * type is introduced. Runtime reachability of the anonymiser call is proven by the owning context's Behat
 * scenario, not here.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureGateTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../../../.audit-resource-types';

    private const string SOURCE_ROOT = __DIR__ . '/../../../../src';

    private const string ANONYMISER = 'AuditResourceAnonymiser';

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

            $source = (string) \file_get_contents($absolute);
            $this->assertStringContainsString(self::ANONYMISER, $source, \sprintf(
                '%s is declared as erasing the person type "%s" but never references %s.',
                $erasurePath,
                $type,
                self::ANONYMISER,
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
        // Looser than the completeness check on purpose: a type may reach `AuditResource::of()` through a
        // constant (as the person type does, so the literal lives in the owning context rather than at the
        // call), which the call-site regex cannot see. Presence of the quoted literal anywhere in `src` is
        // the honest signal that the type is still real.
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

            if (\str_starts_with($classification, 'person')) {
                $parts = \explode('::', $classification, 2);
                $registry[$type] = \trim($parts[1] ?? '');

                continue;
            }

            $registry[$type] = null;
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

            $types = [...$types, ...$constructed[1], ...$routed[1]];
        }

        $types = \array_values(\array_unique($types));
        \sort($types);

        return $types;
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
