<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Support\Dig;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * Plants upload intents through DBAL, in any state and at any point of their clock, so a test can reach the
 * states the HTTP flow passes through in milliseconds. Offsets are seconds from the database's `NOW()`.
 *
 * Every planted intent is deleted after its test. The suite shares one database and the streaming caps count
 * live rows across all actors, so a planted `Streaming` intent left behind would hold a slot of the global cap
 * for the rest of its lease and refuse the next test's claim.
 */
trait PlantsUploadIntents
{
    /** @var list<string> */
    private array $plantedIntentIds = [];

    private ?Connection $plantingConnection = null;

    /**
     * Runs after `tearDown()`, once the kernel is shut down, so it uses the connection the rows were planted
     * through (which reconnects on use) rather than the container, whose reboot here would break the next test's
     * `createClient()`.
     */
    #[After]
    protected function forgetPlantedIntents(): void
    {
        foreach ($this->plantedIntentIds as $id) {
            $this->plantingConnection?->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $id]);
        }

        $this->plantedIntentIds = [];
        $this->plantingConnection = null;
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") a planting helper names the one fact it varies
     */
    private function plantIntent(
        string $actorId,
        UploadIntentState $state,
        bool $claimed = true,
        int $leaseOffset = 60,
        ?string $keyHash = null,
        ?string $scopeId = null,
        ?string $documentId = null,
    ): PlantedIntent {
        $id = Uuid::generate();
        $scope = $scopeId ?? RandomUuid::v4()->toRfc4122();

        $this->intentConnection()->executeStatement(
            'INSERT INTO upload_intent (id, actor_id, scope_id, document_id, committed_at, state, declared_size, '
            . 'declared_sha256, key_hash, retention_reason, legal_basis, created_at, ttl_expires_at, claimed_at, '
            . 'lease_expires_at, upload_deadline_at) VALUES (:id, :actor, :scope, CAST(:document AS UUID), '
            . 'CASE WHEN CAST(:document AS UUID) IS NULL THEN NULL ELSE NOW() END, :state, 10, :sha, :key, '
            . "'technical-file', 'contract', NOW(), NOW() + INTERVAL '1 day', "
            . 'CASE WHEN :claimed THEN NOW() ELSE NULL END, '
            . 'CASE WHEN :claimed THEN NOW() + make_interval(secs => :lease) ELSE NULL END, '
            . "CASE WHEN :claimed THEN NOW() + INTERVAL '10 minutes' ELSE NULL END)",
            [
                'id' => $id,
                'actor' => $actorId,
                'scope' => $scope,
                'document' => $documentId,
                'state' => $state->value,
                'sha' => null === $keyHash ? null : \hash('sha256', 'x'),
                'key' => $keyHash,
                'claimed' => $claimed,
                'lease' => $leaseOffset,
            ],
            ['claimed' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'lease' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $this->plantedIntentIds[] = $id;
        $this->plantingConnection = $this->intentConnection();

        return new PlantedIntent($id, $scope);
    }

    private function mintKey(string $scopeId): void
    {
        $encryptor = self::getContainer()->get(EnvelopeEncryptor::class);
        $this->assertInstanceOf(EnvelopeEncryptor::class, $encryptor);
        $encryptor->encrypt(EncryptionScopeId::forDocument($scopeId), 'x', 'probe');
    }

    private function keyStateOf(string $scopeId): ?string
    {
        $row = $this->intentConnection()->fetchAssociative(
            'SELECT destroyed_at, destroy_reason FROM dek_keystore WHERE encryption_scope_id = :scope',
            ['scope' => EncryptionScopeId::forDocument($scopeId)->toString()],
        );

        if (false === $row) {
            return null;
        }

        return null === Dig::value($row, 'destroyed_at') ? 'live' : Dig::string($row, 'destroy_reason');
    }

    private function intentConnection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
