<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Support\PersonReferenceKeys;
use Erpify\Tests\Support\PersonReferences;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The witness no static check can be: that the CONTAINER really collects the person-reference sources, and
 * that a real orphan in a real table surfaces through the assembled control.
 *
 * The unit gate proves each source declares a key the registry carries and that `config/services.yaml`
 * declares the tag and the iterator. Neither proves the assembled service ends up holding them — an
 * exclusion in the `Erpify\` service resource, an autoconfigure change, a source the compiler removes as
 * unused, and the collection is empty while every file and every line of config is still exactly right.
 *
 * The axis COUNT is asserted exactly, because that is the wiring claim itself: four tagged sources plus the
 * audit collaborator. The ids are asserted by containment, because the suite shares a dirty dev database
 * whose own rows must be unable to make this pass or fail. Which four keys the four sources declare is
 * pinned against the registry by the unit gate, and two sources cannot collapse into one axis because the
 * same gate forbids that pairing — so the count of five is a count of five distinct, registry-backed places.
 *
 * @internal
 */
#[CoversNothing]
final class PersonReferenceCollectionFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testTheAssembledControlChecksEveryPlaceAndReportsAnOrphanUnderItsOwn(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $orphanId = $this->seedMembershipOfAnIdentityThatDoesNotExist();

            $verdict = $this->reconciler()->unreconciledReferences();

            // Derived from the registry, not a literal: one axis per classified person reference plus the
            // audit collaborator. A hard-coded number would go red when a fifth person column legitimately
            // arrives, and would say "the collection lost a source" about the opposite of what happened.
            $this->assertSame(
                $this->expectedAxisCount(),
                $verdict->axesChecked(),
                'the tagged collection does not hold one source per classified person reference',
            );

            $reported = [];

            foreach ($verdict->findings() as $personReferenceFinding) {
                $reported[$personReferenceFinding->axis->key()] = $personReferenceFinding->subjectIds;
            }

            $membershipAxis = Membership::class . '::$userId';
            $this->assertArrayHasKey($membershipAxis, $reported);
            $this->assertContains($orphanId, $reported[$membershipAxis]);
        });
    }

    /**
     * @return string the `user_id` of the seeded membership, which resolves to no `identity_user` row
     */
    private function seedMembershipOfAnIdentityThatDoesNotExist(): string
    {
        $organization = Organization::provision(Uuid::generate(), 'ACME Corp');
        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $organizationId = $organization->getId();
        $this->assertNotNull($organizationId);

        $orphanId = Uuid::generate();
        $this->entityManager->persist(
            Membership::grant(Uuid::generate(), $orphanId, $organizationId, Role::VIEWER),
        );
        $this->entityManager->flush();

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM membership WHERE user_id = CAST(:id AS UUID)',
            ['id' => $orphanId],
        );
        $this->assertIsNumeric($count);
        $this->assertSame(1, (int) $count, 'the orphan membership really exists');

        return $orphanId;
    }

    /**
     * One tagged source per `person ::` line the registry carries (bar the subject's own primary key), plus
     * the audit resource axis, which is a collaborator of its own because `audit_log` has no entity.
     */
    private function expectedAxisCount(): int
    {
        $references = PersonReferences::fromGateLocation(__DIR__);

        return \count(PersonReferenceKeys::referencesIn($references->classification())) + 1;
    }

    private function reconciler(): ReconcileErasedSubjectReferences
    {
        $reconciler = self::getContainer()->get(ReconcileErasedSubjectReferences::class);
        $this->assertInstanceOf(ReconcileErasedSubjectReferences::class, $reconciler);

        return $reconciler;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
