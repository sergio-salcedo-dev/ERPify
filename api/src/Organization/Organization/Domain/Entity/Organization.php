<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The tenant aggregate: an installation's single organization, owner of the identities and memberships
 * created against it. Multi-tenant-ready by design (every membership carries this `organizationId`), but
 * tenancy is not yet operated — there is exactly one organization per installation today, bootstrapped by
 * CLI. The "one organization per installation" rule is enforced by the application service that provisions
 * it, not by a schema constraint, so the day tenancy opens the invariant relaxes without a migration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'organization')]
final class Organization extends AggregateRoot
{
    private const int MAX_NAME_LENGTH = 255;

    private function __construct(
        string $id,
        #[ORM\Column]
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_NAME_LENGTH)]
        private string $name,
    ) {
        parent::__construct();

        $this->id = $id;
    }

    public static function provision(string $id, string $name): self
    {
        return new self($id, \trim($name));
    }

    public function name(): string
    {
        return $this->name;
    }
}
