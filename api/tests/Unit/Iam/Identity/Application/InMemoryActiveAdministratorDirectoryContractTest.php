<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The double stands in for the Doctrine adapter across every use-case unit test in this namespace, so the
 * verdicts it returns have to mean what the port promises. These cases pin the axis on which it can drift
 * without any consumer noticing: how it decides that an id IS the identity an entry names.
 *
 * The wire admits either casing — `Uuid::ensure()` validates a route id without normalising it and
 * `Symfony\Component\Uid\Uuid::isValid` accepts both — while Postgres compares its `uuid` column canonically.
 * So a case-SENSITIVE double answers "permit" where the adapter answers 409 for the very same input, and every
 * other unit test driving this seam becomes a green over the opposite answer, with no adapter test able to
 * notice. The use cases cannot carry this: they compare no ids at all, they delegate the whole decision here.
 *
 * Both halves are pinned, because the double applies the comparison twice and a test for one half would leave
 * the other reading as covered. Their adapter-side counterparts are
 * `DoctrineActiveAdministratorDirectoryTest::testTheSoleActiveAdministratorIsRecognisedAsAMemberUnderAnyUuidCasing()`
 * and `::testCarryingTheAdministratorRoleIsMatchedUnderAnyUuidCasing()`.
 *
 * @internal
 */
#[CoversClass(InMemoryActiveAdministratorDirectory::class)]
final class InMemoryActiveAdministratorDirectoryContractTest extends TestCase
{
    public function testTheSoleAdministratorIsRecognisedWhenTheEntryIsUpperCased(): void
    {
        $directory = new InMemoryActiveAdministratorDirectory([\strtoupper(UserMother::DEFAULT_ID) => true]);

        // The upper-case entry IS the subject, so removing it drains the set and nothing survives.
        $this->assertFalse($directory->survivesRemovalOf(UserMother::DEFAULT_ID));
    }

    public function testTheSoleAdministratorIsRecognisedWhenTheArgumentIsUpperCased(): void
    {
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $this->assertFalse($directory->survivesRemovalOf(\strtoupper(UserMother::DEFAULT_ID)));
    }

    public function testCarryingTheAdministratorRoleIsMatchedUnderEitherCasing(): void
    {
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        $this->assertTrue($directory->holdsAdministratorRole(\strtoupper(UserMother::DEFAULT_ID)));
    }

    /**
     * Both fixtures are chosen so the answer comes from the MEMBERSHIP half rather than from "some other
     * administrator remains". Seeding a live administrator beside the subject makes the first disjunct true
     * and short-circuits, so the case would pass over a double that had lost this half entirely — which is
     * the property the port calls its contract: a set the subject does not belong to is drained by nothing.
     *
     * @param array<string, bool> $adminUserIsActive
     */
    #[DataProvider('provideAnIdentityTheSetDoesNotHoldSurvivesItsOwnRemovalCases')]
    public function testAnIdentityTheSetDoesNotHoldSurvivesItsOwnRemoval(array $adminUserIsActive): void
    {
        $directory = new InMemoryActiveAdministratorDirectory($adminUserIsActive);

        $this->assertTrue($directory->survivesRemovalOf(UserMother::DEFAULT_ID));
    }

    /**
     * @return iterable<string, array{0: array<string, bool>}>
     */
    public static function provideAnIdentityTheSetDoesNotHoldSurvivesItsOwnRemovalCases(): iterable
    {
        yield 'the empty set' => [[]];
        yield 'a set holding only a phantom' => [[UserMother::DEFAULT_ID => false]];
    }
}
