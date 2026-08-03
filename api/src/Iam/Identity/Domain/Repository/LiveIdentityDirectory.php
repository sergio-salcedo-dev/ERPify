<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

/**
 * Consumer-owned port answering the one question a reconciliation of person references has to ask of this
 * context: of these identifiers, which still belong to a live identity?
 *
 * Narrow on purpose, mirroring {@see ActiveAdministratorDirectory}. The alternative was a method on
 * {@see UserRepository}, whose contract is aggregate lifecycle — and whose `findById()` is what this
 * replaces: resolving existence one id at a time hydrates a whole {@see \Erpify\Iam\Identity\Domain\Entity\User}
 * per reference, email and credential hash included, to answer a question that needs neither. A reconciler
 * has no business holding an aggregate-lifecycle port to ask a set membership question (ISP), and nothing
 * but ids crosses this boundary.
 *
 * It answers EXISTENCE, not a verdict. "The rest of these are missed erasures" is a judgement that depends
 * on this context hard-deleting an erased identity, and that reasoning belongs to the use case rather than
 * to a repository.
 */
interface LiveIdentityDirectory
{
    /**
     * The subset of `$ids` that resolves to a row in `identity_user`, in the spelling given.
     *
     * One statement per call rather than one per id: the caller holds every identifier some other table
     * still names, which is bounded by the number of people the installation has ever had. That bound is
     * also the limit — an implementation binding one parameter per id meets PostgreSQL's 65535-parameter
     * ceiling there and fails outright rather than degrading, so a caller far past that scale must chunk.
     *
     * Returning the caller's own spelling keeps the comparison exact for the caller — UUID hex is
     * case-insensitive, so an adapter echoing the database's canonical form would make a correct id look
     * absent to a `===`.
     *
     * @param list<string> $ids
     *
     * @return list<string> empty when none of them exists (and when `$ids` itself is empty)
     */
    public function existingIdsAmong(array $ids): array;
}
