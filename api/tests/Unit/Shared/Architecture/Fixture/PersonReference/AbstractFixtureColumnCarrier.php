<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;

/**
 * An abstract parent declaring a PRIVATE mapped column — the shape a `#[ORM\MappedSuperclass]` takes.
 * `ReflectionClass::getProperties()` on the concrete child does not return it, so a sweep trusting that
 * alone would let the column be persisted through a property it never sees.
 *
 * It is also where the declaration has to live: the property is declared once, here, so the obligation
 * cannot be annotated per child. The parent backs no table of its own, which is why the stranded-declaration
 * check has to resolve it through the children instead of reading the key literally.
 *
 * @internal test fixture
 */
abstract class AbstractFixtureColumnCarrier
{
    #[ORM\Column(name: 'inherited_subject_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Session/Application/PurgeUserSessions.php')]
    private string $inheritedSubjectId = '';

    public function inheritedSubjectId(): string
    {
        return $this->inheritedSubjectId;
    }
}
