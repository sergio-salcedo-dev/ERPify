<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\CompleteInterruptedErasuresMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\DeleteErasedObjectsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\DocumentsMaintenanceSchedule;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ExpireScanLeasesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\PruneCommittedIntentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReapUploadIntentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentNamesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentScansMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentStorageMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportTombstonedDocumentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\RollForwardPromotionsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ScanDueDocumentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepCappedDocumentsMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepOrphanDerivativesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\SweepStagedOrphansMessage;
use Erpify\Tests\Support\ScheduledTicks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one import per scheduled message
 */
#[CoversClass(DocumentsMaintenanceSchedule::class)]
final class DocumentsMaintenanceScheduleTest extends TestCase
{
    #[Test]
    public function itSchedulesEveryMaintenanceTickAtItsOwnPeriod(): void
    {
        $schedule = (new DocumentsMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Message and period together, because a count goes green on either being wrong. The periods are
        // bounds other code relies on: the reaper and the roll-forward run well inside the 90-second lease and
        // the 120-second roll-forward age, the staging sweep well inside the 20-minute orphan age, the scan sweep
        // and the lease expiry inside the 2-minute scan lease, and the retention prune, the cap sweep and the
        // reports are housekeeping a slower cadence only delays.
        $this->assertSame(
            [
                // Sorted by the helper, so the order here is alphabetical and not the declaration order.
                CompleteInterruptedErasuresMessage::class . ' @ every 5 minutes',
                DeleteErasedObjectsMessage::class . ' @ every 60 seconds',
                ExpireScanLeasesMessage::class . ' @ every 60 seconds',
                PruneCommittedIntentsMessage::class . ' @ every 1 hour',
                ReapUploadIntentsMessage::class . ' @ every 60 seconds',
                ReleaseErasedDerivativesMessage::class . ' @ every 5 minutes',
                ReportDocumentNamesMessage::class . ' @ every 1 day',
                ReportDocumentScansMessage::class . ' @ every 1 hour',
                ReportDocumentStorageMessage::class . ' @ every 1 day',
                ReportTombstonedDocumentsMessage::class . ' @ every 1 hour',
                RollForwardPromotionsMessage::class . ' @ every 60 seconds',
                ScanDueDocumentsMessage::class . ' @ every 60 seconds',
                SweepCappedDocumentsMessage::class . ' @ every 5 minutes',
                SweepOrphanDerivativesMessage::class . ' @ every 14400 seconds',
                SweepStagedOrphansMessage::class . ' @ every 5 minutes',
            ],
            ScheduledTicks::describe($schedule),
        );
    }

    #[Test]
    public function itPersistsItsCheckpointSoAPeriodLongerThanTheWorkerCanElapse(): void
    {
        $schedule = (new DocumentsMaintenanceSchedule(new ArrayAdapter()))->getSchedule();

        // Without state the daily report's first run date is `boot + 1 day`, and the worker is replaced hourly.
        $this->assertInstanceOf(
            CacheInterface::class,
            $schedule->getState(),
            'The schedule must be stateful, or its daily period never comes due under a time-limited worker.',
        );
    }
}
