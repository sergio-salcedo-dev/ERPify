<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * One use of the erasure ledger's override: the documented way a restore proceeds although the ledger cannot be read
 * in full. While a row of this table is uncleared no document is read, listed or trailed, whatever
 * `documents.read_enabled` says (`ErasureLedgerOverride`).
 *
 * **It holds no name, label, host user or key fingerprint, and that is a decision.** Any of those is personal data
 * about an operator in a table no erasure path owns, and a label typed on a restored host proves nothing about who
 * typed it. The row holds when, why (a closed reason) and a random `record_ref` the script prints; the operator writes
 * that reference, their identity and the date in the off-host operator log that `docs/vps-deployment.md` prescribes,
 * which is where "who" lives and where it is deleted when the last snapshot older than its entries has expired.
 *
 * Insert-only through the ORM (`readOnly: true`); the one later change, setting `cleared_at`, is a compare-and-set
 * the clearing issues, so no managed instance ever writes it.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'erasure_ledger_override')]
final class LedgerOverrideRecord
{
    #[ORM\Column(name: 'set_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $setAt;

    /** Written only by the clearing's compare-and-set, never through the ORM. */
    #[ORM\Column(
        name: 'cleared_at',
        type: Types::DATETIMETZ_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: false,
    )]
    // @phpstan-ignore property.unusedType (Doctrine hydrates it; a compare-and-set statement writes it)
    private ?DateTimeImmutable $clearedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(length: 32, enumType: LedgerOverrideReason::class)]
        private LedgerOverrideReason $reason,
        #[ORM\Column(name: 'record_ref', type: Types::GUID)]
        private string $recordRef,
    ) {
        Uuid::ensure($this->id);
        Uuid::ensure($this->recordRef);

        $this->setAt = SystemClock::now();
    }

    public static function set(LedgerOverrideReason $reason): self
    {
        return new self(Uuid::generate(), $reason, Uuid::generate());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reason(): LedgerOverrideReason
    {
        return $this->reason;
    }

    public function recordRef(): string
    {
        return $this->recordRef;
    }

    public function setAt(): DateTimeImmutable
    {
        return $this->setAt;
    }

    public function clearedAt(): ?DateTimeImmutable
    {
        return $this->clearedAt;
    }
}
