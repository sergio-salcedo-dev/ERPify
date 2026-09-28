<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\AuditedEntity;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\AuditActionOperationAgreement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Holds every audited aggregate in `api/src` to {@see AuditActionOperationAgreement}: the `action` its
 * {@see AuditedEntity::auditAction()} names for a write ends in that write's operation case name, from one
 * non-empty root — so a `change` row's `action` and its `metadata.operation` never state two different facts
 * about one write.
 *
 * The universe is derived from the tree, never kept by hand: every `.php` under `api/src` whose text names
 * `AuditedEntity` is a candidate (a cheap prefilter — it over-matches on purpose, docblocks included), and
 * reflection decides membership: a concrete class implementing the interface. An aggregate is instantiated
 * without its constructor, which is sound because `auditAction()` is a pure mapping of its argument; one that
 * reads its own state there would fail here rather than pass.
 *
 * An empty universe fails rather than passing: a sweep that finds no aggregate has checked nothing.
 *
 * Declared blind spots: a class implementing the interface only through an interface that extends it, and
 * spelling neither name, is missed by the prefilter; so is a subclass of a concrete aggregate declared in a
 * file that never spells `AuditedEntity`, so an `auditAction()` it overrides goes unchecked; a class whose
 * file path does not map to its FQCN under PSR-4 is never loaded; and nothing here reads what `audit_log`
 * already holds.
 *
 * @internal
 */
#[CoversNothing]
final class AuditActionOperationAgreementGateTest extends TestCase
{
    /** The audited aggregates known today (`Bank`, `BankAccount`); a floor, not a list. */
    private const int KNOWN_AGGREGATE_FLOOR = 2;

    #[Test]
    public function everyAuditedAggregateNamesItsWritesAfterTheOperation(): void
    {
        $aggregates = $this->auditedAggregates();

        $this->assertNotSame(
            [],
            $aggregates,
            'No concrete AuditedEntity was found under api/src, so this gate checked nothing. Either the '
            . 'discovery broke or write auditing was removed — re-derive the gate rather than accept a green.',
        );

        $this->assertGreaterThanOrEqual(
            self::KNOWN_AGGREGATE_FLOOR,
            \count($aggregates),
            \sprintf(
                'Discovery found %d audited aggregate(s): %s. The floor is the count known today (Bank and '
                . 'BankAccount), so fewer means discovery lost one — fix the sweep, and lower the floor only '
                . 'when an aggregate genuinely stops being audited.',
                \count($aggregates),
                \implode(', ', $aggregates),
            ),
        );

        $violations = [];

        foreach ($aggregates as $aggregate) {
            $entity = (new ReflectionClass($aggregate))->newInstanceWithoutConstructor();
            $violations = [...$violations, ...AuditActionOperationAgreement::violations($entity, $aggregate)];
        }

        $this->assertSame([], $violations, \implode("\n", $violations));
    }

    /**
     * @return list<class-string<AuditedEntity>>
     */
    private function auditedAggregates(): array
    {
        $root = ApiSourceFiles::root();
        $classes = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $source = \file_get_contents($file->getPathname());

            if (false === $source || !\str_contains($source, 'AuditedEntity')) {
                continue;
            }

            $relative = \substr($file->getPathname(), \strlen($root) + 1, -\strlen('.php'));
            $fqcn = 'Erpify\\' . \str_replace('/', '\\', $relative);

            if (!\is_subclass_of($fqcn, AuditedEntity::class)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isInterface() || $reflection->isAbstract()) {
                continue;
            }

            $classes[] = $fqcn;
        }

        \sort($classes);

        return $classes;
    }
}
