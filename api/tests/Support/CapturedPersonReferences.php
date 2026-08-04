<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Erpify\Shared\Audit\Domain\AuditedEntity;

/**
 * Which `person ::` lines of the registry sit on an aggregate whose writes the trail captures.
 *
 * The two markers are individually sound and lethal together: `#[PersonSubjectReference]` says a column holds
 * a person's id and names the file that erases it, while {@see AuditedEntity} opts the aggregate into
 * field-level change-data-capture. An entity carrying both copies that id into `audit_log.metadata` as
 * cleartext on every write, and no mutation policy on that table enters the JSON — the prune deletes whole
 * rows, both anonymisers rewrite columns — so the copy outlives the erasure the registry promises.
 *
 * A rule object rather than a private method on the gate, for the reason G-3a's tripwire made expensive: the
 * committed tree contains no entity that carries both markers, so a rule expressed as an assertion over `src`
 * has no red case and cannot be shown to fire. Here it is data in, keys out, so a fixture drives it.
 *
 * The subject's own primary key is NOT exempt, unlike in {@see PersonReferenceKeys}: that exemption says the
 * row's deletion is its own erasure, which is true of the row and false of the copy change-data-capture
 * already took of it.
 *
 * @internal test support
 */
final class CapturedPersonReferences
{
    /**
     * @param array<string, string|null> $classification `<Fqcn>::$<property>` => owner path, null when non-person
     *
     * @return list<string>
     */
    public static function in(array $classification): array
    {
        $captured = [];

        foreach ($classification as $key => $owner) {
            if (null === $owner) {
                continue;
            }

            // Split on the full `::$` separator the registry key is built from, not on `::`. A key missing
            // the separator yields `false` here and is skipped as unparseable rather than collapsing to the
            // empty string, which `is_a()` answers `false` for — the shape that let a malformed or
            // mistyped line pass this rule without ever being weighed.
            $entity = \strstr($key, '::$', true);

            if (false !== $entity && \is_a($entity, AuditedEntity::class, true)) {
                $captured[] = $key;
            }
        }

        return $captured;
    }
}
