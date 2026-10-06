<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * An erased subject whose anonymising passes are re-run for one window after their erasure commits.
 *
 * The erasure rewrites every row naming the subject that exists when it runs. A request already in flight
 * when it starts — one that loaded the subject before the identity row went — can still commit a row naming
 * them afterwards, and the set of writers able to do that has been found incomplete by every review that
 * enumerated it. The re-sweep closes that class by mechanism rather than by list: whatever names the subject
 * inside the window is rewritten on the next tick, whoever wrote it.
 *
 * **This row holds the subject's real id after their erasure, and that is the price of the mechanism.** No
 * other trace of the id survives — that is the erasure's point — so nothing else could tell the sweep what
 * to look for. A fingerprint instead of the id is refused for the reason `docs/adr/audit-activity-log.md`
 * D4 gives: the id space is enumerable, so a hash is a re-identification oracle. What bounds the cost is the
 * owner that deletes the row when the window closes, and the detective source that reports one it failed to
 * delete.
 *
 * It deliberately carries no pseudonym. Holding the erasure's pseudonym beside the real id would be the
 * id→pseudonym mapping table D4 vetoes by name, short-lived or not, so every re-sweep mints its own.
 *
 * It records no domain event: an event about this row would append the subject's real id to `event_store`,
 * which is the very table the re-sweep exists to keep clean.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_erasure_resweep')]
#[ORM\UniqueConstraint(name: 'uniq_identity_erasure_resweep_subject_id', columns: ['subject_id'])]
final class ErasureResweep extends AggregateRoot
{
    /**
     * How long after its scheduling a subject is re-swept, and so how long its real id survives in this row.
     * What that leaves uncovered is recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7 (ADR D4.2 of
     * `docs/adr/audit-activity-log.md`).
     */
    public const string WINDOW = 'PT1H';

    #[ORM\Column(name: 'subject_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Identity/Application/ResweepErasedSubjects.php')]
    private string $subjectId;

    private function __construct(string $subjectId)
    {
        parent::__construct();

        Uuid::ensure($subjectId);

        $this->id = Uuid::generate();
        $this->subjectId = $subjectId;
    }

    public static function scheduleFor(string $subjectId): self
    {
        return new self($subjectId);
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    /**
     * Whether this row's window has closed at `$now`. Strictly later than one window, so a row scheduled
     * exactly one window ago still gets a tick that does not forget it — to the second, since `created_at`
     * is stored without fractions; a five-minute tick never notices the difference.
     */
    public function windowClosedAt(DateTimeImmutable $now): bool
    {
        return $this->createdAt->add(new DateInterval(self::WINDOW)) < $now;
    }
}
