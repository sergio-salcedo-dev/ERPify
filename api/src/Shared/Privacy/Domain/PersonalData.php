<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Domain;

use Attribute;

/**
 * Passive marker: the property it decorates holds personal data (GDPR). The owning module declares it
 * on its entity fields; infrastructure that handles personal data (audit crypto-shredding, and later
 * export/masking) only reads it to decide encrypt-vs-clear per column — none of them decides what is
 * personal. Carries no behaviour, mirroring how {@see \Symfony\Component\Validator\Constraints} and
 * {@see \Doctrine\ORM\Mapping} attributes sit on domain entities.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PersonalData
{
}
