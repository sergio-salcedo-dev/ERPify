<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Doctrine\ORM\Mapping\Entity;
use Erpify\Documents\Document\Domain\Entity\DocumentCustody;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\DocumentCustodyMutations;
use Erpify\Tests\Support\SanctionedLogMutations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Closes the set of statements `src` may issue against `document_custody`, the custody log of the Documents
 * context, to exactly one: the actor anonymisation a person's erasure runs.
 *
 * The log is append-only except for that statement, and for as long as that sentence had no gate behind it,
 * "append-only" meant only that a reviewer might notice. The decision is in `docs/adr/documents-custody-log.md`
 * (D3); the set is declared below as a constant of this gate rather than a registry at the api root, so
 * widening it is a diff to this file — the moment a reviewer has to read. It is the sibling of
 * {@see SanctionedLogMutationGateTest}, which closes the same kind of set for `event_store` and `audit_log` and
 * names those two tables in its engine; this one has its own engine and its own table so neither can silently
 * widen the other. The comparison is EQUALITY, so a declared member the sweep no longer finds is red too: an
 * extractor broken into seeing nothing would otherwise report the cleanest tree possible.
 * {@see DocumentCustodyMutationRulesGateTest} falsifies the engine against one synthetic source per supported
 * surface, and {@see DocumentCustodyMutationBlindSpotGateTest} pins the shapes it does not read.
 *
 * **What a green proves:** every `.php` under `api/src` issues, against `document_custody`, exactly the
 * mutations declared here, as far as the statement is spelled in
 *
 * - SQL in string literals, `.` concatenations, heredocs or `self::`/`static::` constants of the same file
 *   (`UPDATE`, `DELETE`, `MERGE`, `TRUNCATE` — including `TRUNCATE document CASCADE`, which reaches the log
 *   through its foreign key — and `INSERT … ON CONFLICT … DO UPDATE`);
 * - DBAL's `->update('document_custody')` / `->delete('document_custody')`;
 * - DQL and the ORM query builder naming the entity: `createQuery('UPDATE ' . DocumentCustody::class …)`,
 *   `->update(DocumentCustody::class, …)`, `->delete(DocumentCustody::class, …)`, the verb before or after
 *   `->from(DocumentCustody::class, …)` and after `->getRepository(DocumentCustody::class)->createQueryBuilder()`,
 *   and a DQL string spelling the class name;
 * - SQL with a block or line comment between the verb and the table;
 * - `->remove(…)` on any receiver in a file that names the entity;
 *
 * and that the entity is still mapped `readOnly: true`, so the ORM itself emits no `UPDATE` for it.
 *
 * **What it cannot see** (a green says nothing about these; the blind-spot test pins each):
 *
 * - a table name or entity class reaching the statement through interpolation, `sprintf`, a variable, a
 *   parameter, `static::class`, a `use … as` alias, or a constant of ANOTHER class — which includes the
 *   repository of the entity held in a variable before its verb, and a verb in a different statement from the
 *   one naming the entity;
 * - a comment that splits the table name itself;
 * - SQL assembled with `.=` or through parenthesised sub-expressions, and a `->remove()` through a wrapper that
 *   is not called `remove`, or in a file that does not name the entity;
 * - a `;` inside a quoted SQL value, which cuts upsert detection short;
 * - WHICH row the sanctioned statement rewrites, and to what: a descriptor is verb and table only, so the
 *   sanctioned file swapping its predicate or its marker stays green — that is
 *   `DocumentErasureFunctionalTest`;
 * - DDL (`DROP`, `ALTER TABLE`), which is schema and belongs to migrations;
 * - SQL outside `api/src`: migrations and tests are not swept on purpose (the test suites truncate the log
 *   between cases), nor are operator scripts or a psql session;
 * - database-side writers: **no role separation, `GRANT`, `REVOKE` or trigger exists in the tree**, so a
 *   second writer to the database is not stopped by anything — adding a restricted role or a `BEFORE
 *   UPDATE/DELETE` trigger is a separate decision, and custody is not claimed cryptographically immutable;
 * - whether the sanctioned statement is reached, or runs in the transaction the erasure requires.
 *
 * **The fail-safe direction is red.** Any literal in `src` that reads like a mutation — an exception message,
 * a statement held whole in a constant and used through `self::` (which counts twice) — turns this red, and so
 * does `->remove()` in a file that names the entity for an unrelated receiver. The failure message says so, so
 * a false positive is not mistaken for a change of policy.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentCustodyMutationGateTest extends TestCase
{
    /**
     * The closed set, file (relative to `api/src`) → mutations in order of appearance.
     */
    private const array SANCTIONED = [
        self::REPOSITORY_DIRECTORY . 'DoctrineDocumentCustodyRepository.php' => ['UPDATE document_custody'],
    ];

    private const string REPOSITORY_DIRECTORY = 'Documents/Document/Infrastructure/Persistence/Doctrine/';

    #[Test]
    public function srcMutatesTheCustodyLogThroughExactlyTheSanctionedStatement(): void
    {
        $discrepancies = SanctionedLogMutations::discrepancies(self::SANCTIONED, $this->mutationsInSrc());

        $this->assertSame([], $discrepancies, \sprintf(
            "The closed set of mutations on document_custody no longer matches what src issues:\n- %s\n\n"
            . 'A new UPDATE, DELETE, TRUNCATE, MERGE, upsert or remove() on the custody log is a change of '
            . 'policy, not of code: it needs its own decision in docs/adr/documents-custody-log.md (D3) and then '
            . 'a line in SANCTIONED. A sanctioned member the sweep no longer finds is either gone — remove it and '
            . 'say so in the ADR — or written in a shape the engine cannot read, which leaves the log '
            . 'unguarded. A red can also be a false positive — the engine reads string literals and ->remove() '
            . 'calls by name — so reword or restructure rather than widening SANCTIONED.',
            \implode("\n- ", $discrepancies),
        ));
    }

    #[Test]
    public function theEntityStaysReadOnlySoTheOrmEmitsNoUpdateForIt(): void
    {
        $attributes = (new ReflectionClass(DocumentCustody::class))->getAttributes(Entity::class);

        $this->assertCount(1, $attributes);
        $this->assertTrue(
            $attributes[0]->newInstance()->readOnly,
            'DocumentCustody lost #[ORM\Entity(readOnly: true)]: the ORM would now emit an UPDATE for any '
            . 'change made to a managed row, on a surface this gate cannot read.',
        );
    }

    #[Test]
    public function theSweepReadsTheRealTreeAndIsNotVacuous(): void
    {
        $files = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            ++$files;
            $this->assertNotFalse(\file_get_contents($file->getPathname()));
        }

        $this->assertGreaterThan(500, $files, 'the walk must cover api/src or an empty sweep reads as a clean tree');
        $this->assertNotSame([], $this->mutationsInSrc(), 'the sanctioned statement must be found by the engine');
    }

    /**
     * @return array<string, list<string>>
     */
    private function mutationsInSrc(): array
    {
        $found = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            $source = \file_get_contents($file->getPathname());

            $this->assertIsString($source, \sprintf('%s could not be read.', $file->getPathname()));

            $mutations = DocumentCustodyMutations::in($source);

            if ([] !== $mutations) {
                $found[\substr($file->getPathname(), \strlen(ApiSourceFiles::root()) + 1)] = $mutations;
            }
        }

        return $found;
    }
}
