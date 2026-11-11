<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\PurgeUserDocumentReferences;
use Erpify\Documents\Document\Application\Upload\ReapUploadIntents;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalUploadIntentActorPersonReferences;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineUploadIntentRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A person's erasure as it reaches the upload intents: every intent of the actor in every state loses the actor
 * and the key hash, the live ones are expired, none is deleted — so the reaper still tombstones a scope a claim
 * minted — and the detective source stops reporting the person.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(PurgeUserDocumentReferences::class)]
#[CoversClass(DoctrineUploadIntentRepository::class)]
#[CoversClass(DbalUploadIntentActorPersonReferences::class)]
final class UploadIntentErasureFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testEveryIntentOfThePersonIsAnonymisedAndOnlyTheLiveOnesExpire(): void
    {
        $actor = Uuid::generate();
        $bystander = Uuid::generate();
        $declared = $this->plantIntent(
            $actor,
            UploadIntentState::Declared,
            claimed: false,
            keyHash: \hash('sha256', Uuid::generate()),
        );
        $streaming = $this->plantIntent(
            $actor,
            UploadIntentState::Streaming,
            keyHash: \hash('sha256', Uuid::generate()),
        );
        $committed = $this->plantIntent(
            $actor,
            UploadIntentState::Committed,
            keyHash: \hash('sha256', Uuid::generate()),
            documentId: Uuid::generate(),
        );
        $other = $this->plantIntent($bystander, UploadIntentState::Declared, claimed: false);

        $count = $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        );

        $this->assertSame(3, $count);

        foreach ([$declared, $streaming, $committed] as $intent) {
            $row = $this->intentConnection()->fetchAssociative(
                'SELECT * FROM upload_intent WHERE id = :id',
                ['id' => $intent->id],
            );
            $this->assertIsArray($row, 'the purge never deletes an intent');
            $this->assertSame(ErasedPersonMarker::UUID, Dig::value($row, 'actor_id'));
            $this->assertNull(Dig::value($row, 'key_hash'));
        }

        $this->assertSame('expired', $this->stateOf($declared->id));
        $this->assertSame('expired', $this->stateOf($streaming->id));
        $this->assertSame(
            'committed',
            $this->stateOf($committed->id),
            'its document exists; the retention sweep removes it',
        );
        $this->assertSame($bystander, $this->intentConnection()->fetchOne(
            'SELECT actor_id FROM upload_intent WHERE id = :id',
            ['id' => $other->id],
        ));

        $retained = $this->service(DbalUploadIntentActorPersonReferences::class)->retainedPersonIds();
        $this->assertNotContains($actor, $retained);
        $this->assertNotContains(ErasedPersonMarker::UUID, $retained, 'the marker names nobody');
        $this->assertContains($bystander, $retained);
    }

    public function testTheReaperStillTombstonesAScopeAClaimOfAnErasedPersonMinted(): void
    {
        $actor = Uuid::generate();
        $streaming = $this->plantIntent($actor, UploadIntentState::Streaming);
        $this->mintKey($streaming->scopeId);

        $this->service(TransactionManager::class)->transactional(
            fn (): int => $this->service(PurgeUserDocumentReferences::class)->eraseReferencesTo($actor),
        );

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($streaming->id));
        $this->assertSame('aborted-upload', $this->keyStateOf($streaming->scopeId));
    }

    public function testANeverClaimedIntentIsReapedWithoutATombstone(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired, claimed: false);

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($intent->id));
        $this->assertNull($this->keyStateOf($intent->scopeId), 'an abandoned declare adds no keystore row');
    }

    public function testAClaimedIntentWithNoKeyStillLeavesATombstone(): void
    {
        $intent = $this->plantIntent(Uuid::generate(), UploadIntentState::Expired);

        $this->assertTrue($this->service(ReapUploadIntents::class)->reapOne($intent->id));
        $this->assertSame('aborted-upload', $this->keyStateOf($intent->scopeId), 'a late writer cannot mint under it');
    }

    private function stateOf(string $id): mixed
    {
        return $this->intentConnection()->fetchOne('SELECT state FROM upload_intent WHERE id = :id', ['id' => $id]);
    }
}
