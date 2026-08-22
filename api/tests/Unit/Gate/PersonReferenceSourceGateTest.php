<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Privacy\Application\PersonReferenceSource;
use Erpify\Tests\Support\PersonReferenceKeys;
use Erpify\Tests\Support\PersonReferences;
use Erpify\Tests\Support\PersonReferenceSourceCoverage;
use Erpify\Tests\Support\PersonReferenceSources;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Static gate over the detective control's coverage: every column `api/.person-reference-policy` classifies
 * as a reference to a person must have a source wired into the reconciler, and every source must cover a
 * column the registry carries. The derivation rules live in {@see PersonReferenceSources} and
 * {@see PersonReferenceSourceCoverage}, their falsifiability in {@see PersonReferenceSourceRulesGateTest};
 * this class is the assertions over the real tree.
 *
 * It exists because the sibling gate proves the erasure is WRITTEN and this control is what proves the row
 * is GONE — and a detective control whose set of sources is maintained by hand needs a detective control of
 * its own. The proof that this is not theoretical is the story that built it: its first cut enumerated the
 * columns by hand and named two of the four.
 *
 * `audit_log.resource_id` is deliberately outside this comparison. Its table has no Doctrine entity, so no
 * property declares the column and the registry's reflection sweep is structurally blind to it — requiring
 * it to be a registry key would make the second direction below red by construction. It stays a collaborator
 * of the reconciler in its own right, and its own registry (`api/.audit-resource-types`) governs it.
 *
 * @internal
 */
#[CoversNothing]
final class PersonReferenceSourceGateTest extends TestCase
{
    public const string FAILURE_PREAMBLE
        = 'A persisted reference to a person must be reachable by the detective control, or its erasure is '
        . 'only ever proven to be WRITTEN and never to have HAPPENED. The set of places the control reaches '
        . 'is derived from api/.person-reference-policy rather than maintained by hand, so a new context '
        . 'that comes to hold a person id cannot join the registry and skip the control.';

    private const string SOURCE_TAG = 'erpify.person_reference_source';

    private const string RECONCILER = \Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences::class;

    #[Test]
    public function everyPersonReferenceHasASourceInTheDetectiveControl(): void
    {
        $uncovered = PersonReferenceSourceCoverage::uncoveredIn(
            $this->references()->classification(),
            $this->sources()->declaredAxes(),
        );

        $this->assertSame([], $uncovered, self::FAILURE_PREAMBLE . "\n" . \sprintf(
            "These columns are classified as person references but no %s covers them:\n  %s",
            PersonReferenceSource::class,
            \implode("\n  ", $uncovered),
        ));
    }

    #[Test]
    public function everySourceCoversAColumnTheRegistryCarries(): void
    {
        $unbacked = PersonReferenceSourceCoverage::unbackedIn(
            $this->references()->classification(),
            $this->sources()->declaredAxes(),
        );

        $this->assertSame([], $unbacked, \sprintf(
            'These axes are declared by a person-reference source but the registry carries no such person '
            . "reference:\n  %s\nA source watching a key nobody classified attributes its findings to "
            . 'something no reviewer can look up — and when the key is merely misspelled, the column it was '
            . 'written for is uncovered while the number of sources still looks right.',
            \implode("\n  ", $unbacked),
        ));
    }

    #[Test]
    public function noAxisIsCoveredByTwoSources(): void
    {
        $duplicated = PersonReferenceSourceCoverage::duplicatedIn($this->sources()->declaredAxes());

        $this->assertSame([], $duplicated, \sprintf(
            'These axes are claimed by more than one source: %s. The verdict is keyed by the axis, so the '
            . 'second source replaces the first and one column goes unreported while both classes look wired.',
            \json_encode($duplicated, JSON_THROW_ON_ERROR),
        ));
    }

    #[Test]
    public function theCollectionIsWiredIntoTheReconciler(): void
    {
        // Both halves, because either alone leaves the collection empty while every source still exists in
        // the tree and this gate's comparison still passes: the control would then check the audit axis
        // only, and say so nowhere.
        $services = $this->services();
        $tags = $this->arrayAt($services, '_instanceof', PersonReferenceSource::class, 'tags');

        $this->assertContains(self::SOURCE_TAG, $tags, \sprintf(
            'config/services.yaml no longer tags %s, so nothing collects the sources.',
            PersonReferenceSource::class,
        ));

        $arguments = $this->arrayAt($services, self::RECONCILER, 'arguments');
        $argument = $arguments['$personReferenceSources'] ?? null;

        $this->assertInstanceOf(TaggedValue::class, $argument);
        $this->assertSame('tagged_iterator', $argument->getTag());
        $this->assertSame(self::SOURCE_TAG, $argument->getValue(), \sprintf(
            'The reconciler no longer receives the "%s" iterator, so its collection of sources is empty.',
            self::SOURCE_TAG,
        ));
    }

    #[Test]
    public function theGateScansTheRealTreeAndCanFire(): void
    {
        // A silent zero-scan on either side would make every check above vacuously green: with no sources
        // discovered, the second and third pass by having no subject, and with no person references derived
        // the first does too.
        $classification = $this->references()->classification();

        $this->assertNotEmpty(
            $this->sources()->declaredAxes(),
            'The gate discovered zero person-reference sources, so both coverage directions are vacuous.',
        );
        $this->assertNotEmpty(
            PersonReferenceKeys::referencesIn($classification),
            'The registry classifies no column as a person reference, so nothing has to be covered at all.',
        );
    }

    /**
     * @return array<mixed>
     */
    private function services(): array
    {
        $parsed = Yaml::parseFile(
            \dirname(__DIR__, 4) . '/config/services.yaml',
            Yaml::PARSE_CUSTOM_TAGS,
        );

        $this->assertIsArray($parsed);

        return $this->arrayAt($parsed, 'services');
    }

    /**
     * Walks `$path` through the parsed config, failing at the first step that is absent — so a deleted tag
     * block reports which key vanished instead of a null-offset notice three levels down.
     *
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function arrayAt(array $tree, string ...$path): array
    {
        $node = $tree;
        $walked = [];

        foreach ($path as $key) {
            $walked[] = $key;
            $next = $node[$key] ?? null;
            $this->assertIsArray($next, \sprintf(
                'config/services.yaml carries no array at "%s".',
                \implode(' → ', $walked),
            ));
            $node = $next;
        }

        return $node;
    }

    private function references(): PersonReferences
    {
        return PersonReferences::fromGateLocation(__DIR__);
    }

    private function sources(): PersonReferenceSources
    {
        return PersonReferenceSources::fromGateLocation(__DIR__);
    }
}
