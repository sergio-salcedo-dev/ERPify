<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\PersonReference;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;

/**
 * The entity the person-reference gate sweeps when it is driven over the fixture tree instead of `src`. It
 * lets the two directions of the registry check be pinned against forms the committed registry must never
 * contain — a column with no line, a line no column backs — without a dirty entry ever existing in the real
 * one.
 *
 * `$label` is not a `Types::GUID` column and must therefore stay OUT of the derived universe: without it, a
 * derivation that returned every property would look identical to the correct one.
 *
 * `$custodian`, `$delegate` and `$deputy` are the forms a to-one association takes. None declares an
 * `#[ORM\Column]`, and only one declares a `#[ORM\JoinColumn]` — that attribute is optional, because Doctrine
 * synthesises the column from the field name. All three persist a foreign key, so all three belong to the
 * universe, and each association attribute has a member of its own: a sweep keying on the join column, or
 * reading only `ManyToOne`, drops one of them and the exact-list assertion goes red.
 *
 * Doctrine never maps it: `auto_mapping` is off and the mappings point at `src/` alone.
 *
 * @internal test fixture
 */
#[ORM\Entity]
final class PersonReferenceFixtureEntity
{
    #[ORM\Column(name: 'subject_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Session/Application/PurgeUserSessions.php')]
    public string $subjectId = '';

    #[ORM\Column(name: 'tenant_id', type: Types::GUID)]
    public string $tenantId = '';

    #[ORM\Column(name: 'label')]
    public string $label = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'custodian_id')]
    public ?self $custodian = null;

    #[ORM\ManyToOne]
    public ?self $delegate = null;

    #[ORM\OneToOne]
    public ?self $deputy = null;
}
