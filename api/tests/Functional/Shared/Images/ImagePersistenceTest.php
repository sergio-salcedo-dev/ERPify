<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The aggregate against real Postgres. Two properties live here that nothing else can observe: that a
 * row round-trips without the constructor running again, and that the table holds exactly the seven
 * fields the contract allows.
 *
 * Each case runs inside a transaction that is always rolled back — the suite has no DAMA auto-rollback
 * and shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(Image::class)]
final class ImagePersistenceTest extends KernelTestCase
{
    /**
     * Hydration must not re-run the constructor, which would stamp `createdAt` with the wall clock of
     * the read instead of the write.
     *
     * The instant is far away AND carries microseconds on purpose. A "now"-based assertion passes even
     * under a total re-stamp whenever both operations land in the same second, and the column is
     * `TIMESTAMP(6) WITH TIME ZONE` precisely so a re-stamp moves the value even inside one second.
     * Reading a clock race as evidence is what makes this test unfalsifiable.
     */
    public function testAPersistedImageRoundTripsWithoutRestampingCreatedAt(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $entityManager): void {
            $stamped = new DateTimeImmutable('2020-01-01T00:00:00.123456+00:00');
            $imageId = ImageId::generate();

            SystemClock::set(new FixedClock($stamped));

            try {
                $image = new Image($imageId, \str_repeat('a', 64), 'image/webp', 800, 600, 4096);
                $entityManager->persist($image);
                $entityManager->flush();
            } finally {
                SystemClock::reset();
            }

            $entityManager->clear();

            $found = $entityManager->find(Image::class, $imageId->toString());

            $this->assertInstanceOf(Image::class, $found);
            $this->assertNotSame($image, $found, 'the identity map must be cold, or this asserts nothing');

            $this->assertSame(
                $stamped->format('Y-m-d\TH:i:s.u'),
                $found->createdAt()->format('Y-m-d\TH:i:s.u'),
                'createdAt survives the round trip to the microsecond; a re-stamp moves it',
            );

            $this->assertTrue($imageId->equals($found->id()), 'the identity round-trips as its value object');
            $this->assertSame(\str_repeat('a', 64), $found->digest());
            $this->assertSame('image/webp', $found->mediaType());
            $this->assertSame(800, $found->width());
            $this->assertSame(600, $found->height());
            $this->assertSame(4096, $found->byteSize());
        });
    }

    /**
     * The schema is asserted against the real PostgreSQL catalog, not against the Doctrine mapping:
     * `doctrine:mapping:info` only proves Doctrine sees the entity, and a missing mapping entry makes
     * `make db.diff` emit an empty migration with no error at all.
     *
     * The assertion is a whole-set comparison plus an explicit non-empty guard, because a
     * "every actual column is expected" loop passes over zero rows — a missing table, a wrong schema
     * filter or an unapplied migration would all read as success.
     */
    public function testTheTableHoldsExactlyTheSevenFieldsTheContractAllows(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $entityManager): void {
            $columns = $entityManager->getConnection()->fetchAllAssociative(
                'SELECT column_name, data_type, is_nullable FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = :table
                 ORDER BY column_name',
                ['table' => 'image'],
            );

            $this->assertNotSame([], $columns, 'the table must exist, or the set comparison below is vacuous');

            // Type and nullability, not only the name. A column set alone cannot see `digest` widening to
            // `text`, `byte_size` becoming nullable, or `created_at` losing its time zone — each of which
            // changes what the aggregate can hold while every name stays where it was.
            $this->assertSame([
                ['column_name' => 'byte_size', 'data_type' => 'integer', 'is_nullable' => 'NO'],
                ['column_name' => 'created_at', 'data_type' => 'timestamp with time zone', 'is_nullable' => 'NO'],
                ['column_name' => 'digest', 'data_type' => 'character varying', 'is_nullable' => 'NO'],
                ['column_name' => 'height', 'data_type' => 'integer', 'is_nullable' => 'NO'],
                ['column_name' => 'id', 'data_type' => 'uuid', 'is_nullable' => 'NO'],
                ['column_name' => 'media_type', 'data_type' => 'character varying', 'is_nullable' => 'NO'],
                ['column_name' => 'width', 'data_type' => 'integer', 'is_nullable' => 'NO'],
            ], $columns, 'exactly seven fields: no updated_at, no owner_id, no filename, no storage_path, no url');
        });
    }

    /**
     * The index set as a whole, because the criterion this table turns on is an ABSENCE and a column list
     * cannot see it: two uploads of identical bytes are two independent images, so a unique index on
     * `digest` would be deduplication introduced through the back door — and adding one leaves every
     * assertion about columns green.
     *
     * Asserted as the complete set rather than as "no index on digest", so a foreign key or a covering
     * index arriving with a future consumer has to be a deliberate edit here rather than a silent
     * addition. The primary key is asserted in the same breath: it is what makes a reused identifier fail
     * instead of overwriting.
     */
    public function testTheOnlyIndexOnTheTableIsItsPrimaryKey(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $entityManager): void {
            $connection = $entityManager->getConnection();

            $indexes = $connection->fetchAllKeyValue(
                'SELECT indexname, indexdef FROM pg_indexes
                 WHERE schemaname = current_schema() AND tablename = :table
                 ORDER BY indexname',
                ['table' => 'image'],
            );

            $this->assertNotSame([], $indexes, 'the table must exist, or this proves nothing');
            $this->assertSame(['image_pkey'], \array_keys($indexes), 'no unique digest index, no extra index');

            $primaryKey = $indexes['image_pkey'] ?? null;
            $this->assertIsString($primaryKey);
            $this->assertStringContainsString('UNIQUE INDEX image_pkey ON', $primaryKey);
            $this->assertStringContainsString('(id)', $primaryKey, 'the identity is the key');

            $constraints = $connection->fetchFirstColumn(
                "SELECT constraint_type FROM information_schema.table_constraints
                 WHERE table_schema = current_schema() AND table_name = :table AND constraint_type <> 'CHECK'
                 ORDER BY constraint_type",
                ['table' => 'image'],
            );

            $this->assertSame(['PRIMARY KEY'], $constraints, 'no foreign key: this module references nobody');
        });
    }

    /**
     * `refresh()` is deliberately outside this aggregate's contract, and this pins the boundary rather
     * than leaving the next consumer to discover it. Doctrine's readonly guard compares the incoming
     * value by object identity, so a rehydrated `DateTimeImmutable` is never the same instance and the
     * write is refused. That is a legitimate consequence of an immutable aggregate, not a defect.
     */
    public function testRefreshingAManagedImageIsRefusedBecauseTheAggregateIsImmutable(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $entityManager): void {
            $image = new Image(ImageId::generate(), \str_repeat('b', 64), 'image/png', 10, 10, 128);
            $entityManager->persist($image);
            $entityManager->flush();

            $this->expectException(LogicException::class);
            // The message, not only the type: `ORMInvalidArgumentException` is a `LogicException` too, so
            // a type-only assertion would pass for a refusal that had nothing to do with immutability —
            // which is the entire claim this test's name makes.
            $this->expectExceptionMessageIsOrContains('Attempting to change readonly property');
            $entityManager->refresh($image);
        });
    }

    /**
     * @param callable(EntityManagerInterface): void $work
     */
    private function withEntityManager(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $work($entityManager);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
