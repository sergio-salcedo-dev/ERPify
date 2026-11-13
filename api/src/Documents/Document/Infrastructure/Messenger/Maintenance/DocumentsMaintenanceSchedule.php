<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The Documents maintenance schedule: every crash path of an upload is owned by one of these ticks, consumed from
 * the `scheduler_documents_maintenance` transport by the `scheduler_worker` (prod) and the `messenger_worker`
 * (dev). A schedule that no consume command names compiles, registers and ships dead, so the transport is named
 * in both root compose files and `make php.lint.schedule-consumption` holds it there.
 *
 * **The periods follow the bounds they serve.** The reaper runs every minute because the staging age-sweep margin
 * (`UploadLimits::STAGING_ORPHAN_AGE_SECONDS`) is computed with a 60 s reaper cadence; the roll-forward runs every
 * minute so a document whose writer died is promoted, and so scannable, within minutes. The staging sweep removes
 * objects at least twenty minutes old, so five minutes is fine-grained enough; the retention prune works in days
 * and runs hourly; the report observes state that stays wrong until someone repairs it, so it runs daily.
 *
 * The scan ticks are the scan's drivers, not hints: the scan sweep claims what is due on `next_scan_at`, the lease
 * expiry takes back a scan whose worker died (a 2 minute lease, so a minute is fine-grained enough), and the cap
 * sweep ends a `Received` row a lowered cap would otherwise strand. One scan pass may START documents for a minute
 * and then finishes the one in flight, which is why it fits the margin the reaper's cadence is computed on. The
 * scan report is hourly: it observes documents that wait for a person or an outage, in hours.
 *
 * **Every tick is idempotent and safe under a duplicate.** One `scheduler_worker` replica is an operating policy,
 * not a correctness dependency: each intent and each row is decided under its own lock and rechecked there, a
 * deletion of an absent object is already satisfied, and the report only reads.
 *
 * **`stateful()` on `cache.scheduler_checkpoint`** for the reason `IdentityMaintenanceSchedule` gives: the hourly
 * and daily ticks would otherwise be re-anchored by every worker restart and every deploy, and never fire.
 */
#[AsSchedule('documents_maintenance')]
final readonly class DocumentsMaintenanceSchedule implements ScheduleProviderInterface
{
    private const string EVERY_MINUTE = '60 seconds';

    public function __construct(
        #[Autowire(service: 'cache.scheduler_checkpoint')]
        private CacheInterface $checkpointState,
    ) {
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->checkpointState)
            ->add(RecurringMessage::every(self::EVERY_MINUTE, new ReapUploadIntentsMessage()))
            ->add(RecurringMessage::every(self::EVERY_MINUTE, new RollForwardPromotionsMessage()))
            ->add(RecurringMessage::every('5 minutes', new SweepStagedOrphansMessage()))
            ->add(RecurringMessage::every(self::EVERY_MINUTE, new ScanDueDocumentsMessage()))
            ->add(RecurringMessage::every(self::EVERY_MINUTE, new ExpireScanLeasesMessage()))
            ->add(RecurringMessage::every('5 minutes', new SweepCappedDocumentsMessage()))
            ->add(RecurringMessage::every('1 hour', new PruneCommittedIntentsMessage()))
            ->add(RecurringMessage::every('1 hour', new ReportDocumentScansMessage()))
            ->add(RecurringMessage::every('1 day', new ReportDocumentStorageMessage()))
        ;
    }
}
