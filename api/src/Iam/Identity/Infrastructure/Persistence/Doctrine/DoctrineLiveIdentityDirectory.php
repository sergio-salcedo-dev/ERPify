<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Application\CorruptIdentityRow;
use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link LiveIdentityDirectory} over `identity_user` via plain DBAL — indexed primary-key probes over bounded
 * chunks of the batch, never a hydration and never a mutation.
 *
 * `id` is a `uuid` column, so Postgres resolves the untyped list parameters to `uuid` and compares them
 * canonically; the case-insensitivity of RFC 4122 hex is therefore free in SQL. It is not free in PHP, which
 * is why the returned ids are mapped back to the caller's own spelling instead of being handed on as the
 * database wrote them — a caller diffing with `===` would otherwise read a differently-cased but present id
 * as a missing one, and this port's whole output feeds such a difference.
 *
 * The empty batch returns before touching the connection — "no ids to ask about" has an answer that needs no
 * query. It is an optimisation and nothing more: DBAL expands an empty array parameter to the literal `NULL`,
 * so the statement would be valid `… IN (NULL)` and would correctly return nothing.
 *
 * Chunking lives here because its reason does: every id is one bound parameter, and the PostgreSQL wire
 * protocol caps a statement at 65535 of them — a hard ceiling past which the driver fails outright. The batch
 * is the number of distinct people the installation has ever referenced, which nothing bounds, so it is split
 * into statements of at most `chunkSize` ids. Each id lands in exactly one chunk and is probed once, so a
 * subject gets one verdict however the batch is cut; the lookup set is built over the union of every chunk's
 * answer, and the caller's list is filtered against it once, in the caller's order and spelling.
 */
#[AsAlias(LiveIdentityDirectory::class)]
final readonly class DoctrineLiveIdentityDirectory implements LiveIdentityDirectory
{
    public const int DEFAULT_CHUNK_SIZE = 5000;

    private const int MAX_BOUND_PARAMETERS = 65535;

    /**
     * @var int<1, 65535>
     */
    private int $chunkSize;

    public function __construct(
        private Connection $connection,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($chunkSize < 1 || $chunkSize > self::MAX_BOUND_PARAMETERS) {
            throw new InvalidArgumentException(\sprintf(
                'Identity probe chunk size must be between 1 and %d, got %d.',
                self::MAX_BOUND_PARAMETERS,
                $chunkSize,
            ));
        }

        $this->chunkSize = $chunkSize;
    }

    /**
     * @param string[] $ids
     *
     * @throws CorruptIdentityRow when a row holds something an identity id cannot be — this control's output
     *                            is an absence, so a dropped id would be reported as an erased person
     */
    #[Override]
    public function existingIdsAmong(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Keyed as a lookup set rather than scanned: `in_array` inside the filter is a linear scan per
        // candidate, so the pair is quadratic in the number of people the installation has ever had — the
        // very size this batch exists to handle. UUIDs always contain hyphens, so none of them can be coerced
        // into an integer array key.
        $live = [];

        foreach (\array_chunk($ids, $this->chunkSize) as $chunk) {
            $found = $this->connection->fetchFirstColumn(
                'SELECT id FROM identity_user WHERE id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );

            foreach ($found as $id) {
                $live[\strtolower($this->asIdentityId($id))] = true;
            }
        }

        return \array_values(\array_filter(
            $ids,
            static fn (string $id): bool => isset($live[\strtolower($id)]),
        ));
    }

    private function asIdentityId(mixed $id): string
    {
        if (!\is_string($id)) {
            throw CorruptIdentityRow::idIsNotAString('identity_user.id');
        }

        return $id;
    }
}
