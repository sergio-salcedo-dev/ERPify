<?php

declare(strict_types=1);

namespace Erpify\Shared\Kernel\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

trait Identifiable
{
    /**
     * Doctrine "assigned" identifier: the application layer generates the id (UUID v7 via
     * {@see \Erpify\Shared\Uuid\Domain\Uuid::generate()}) and assigns it before persist.
     * No {@see ORM\GeneratedValue} — Doctrine must not overwrite the app-assigned id (it previously
     * minted a divergent v7 PK, breaking id-based domain-event consumers on creation).
     *
     * Never annotate this with `#[PersonSubjectReference]`, and note that the gate already refuses it: the
     * trait is used by every entity, so one declaration here would claim the same erasure owner for all of
     * their primary keys at once, and the agreement check would fail on the first one classified
     * `non-person`. The deeper reason is that a primary key is not a reference — it IS the row, and erasing
     * it is deleting that row, which is why `make php.lint.person-reference` requires the declaration on
     * every person reference EXCEPT the subject's own key. Whether an id denotes a person is decided per
     * entity in `api/.person-reference-policy`, which is where `User::$id` is classified.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    #[Assert\Uuid(strict: true)]
    protected ?string $id = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;

        return $this;
    }
}
