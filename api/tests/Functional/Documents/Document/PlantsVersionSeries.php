<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Upload\ClaimedUpload;
use Erpify\Documents\Document\Application\Upload\CommitUpload;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\OriginalFilename;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Version series planted straight through DBAL — a first version, or a version under a predecessor in any state —
 * and the one real way in: a new version committed through {@see CommitUpload} from a planted streaming intent.
 *
 * Planted versions are created a second apart, oldest first, so the series projection's creation order is the planting
 * order whatever the clock does between two statements.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait PlantsVersionSeries
{
    use PlantsDocumentRows;
    use PlantsUploadIntents;

    private int $plantedVersions = 0;

    /**
     * Plants a version: a first version of its own series when `$predecessorId` is null, otherwise a successor in the
     * predecessor's series. Returns its id.
     */
    private function plantVersion(
        DocumentState $state,
        ?string $predecessorId = null,
        ?string $uploaderId = null,
    ): string {
        $id = Uuid::generate();
        $seriesId = null === $predecessorId ? $id : $this->seriesOf($predecessorId);

        if (null === $predecessorId) {
            $this->plantSeries($id);
        }

        $this->connection()->executeStatement(
            'INSERT INTO document (id, scope_id, series_id, predecessor_id, uploader_id, state, digest, size_bytes, '
            . 'next_scan_at, created_at, updated_at) VALUES (:id, :scope, :series, CAST(:predecessor AS UUID), '
            . ':uploader, :state, :digest, 10, NOW(), '
            . "date_trunc('second', NOW() AT TIME ZONE 'UTC') - make_interval(secs => :age), NOW())",
            [
                'id' => $id,
                'scope' => RandomUuid::v4()->toRfc4122(),
                'series' => $seriesId,
                'predecessor' => $predecessorId,
                'uploader' => $uploaderId ?? Uuid::generate(),
                'state' => $state->value,
                'digest' => \hash('sha256', $id),
                'age' => 1_000 - $this->plantedVersions++,
            ],
        );

        return $id;
    }

    private function seriesOf(string $documentId): string
    {
        return $this->stringOf($this->connection()->fetchOne(
            'SELECT series_id FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        ));
    }

    private function stateOfDocument(string $documentId): string
    {
        return $this->stringOf($this->connection()->fetchOne(
            'SELECT state FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        ));
    }

    /**
     * A streaming upload declared as a new version of `$predecessorId`, its key minted, ready to commit.
     */
    private function streamingVersionOf(string $predecessorId): ClaimedUpload
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Streaming, predecessorId: $predecessorId);
        $this->mintKey($intent->scopeId);

        return new ClaimedUpload($intent->id, $intent->scopeId, 10, null, OriginalFilename::fromString('v.pdf'));
    }

    private function commitVersion(ClaimedUpload $claimed): Document
    {
        $commit = self::getContainer()->get(CommitUpload::class);
        $this->assertInstanceOf(CommitUpload::class, $commit);

        return $commit->commit($claimed, \hash('sha256', $claimed->intentId));
    }
}
