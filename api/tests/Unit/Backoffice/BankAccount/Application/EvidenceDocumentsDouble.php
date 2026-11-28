<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Evidence\DescribeEvidenceDocuments;
use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use LogicException;
use Override;

/**
 * The Documents seam as the BankAccount use cases meet it in a unit test: the real {@see DescribeEvidenceDocuments}
 * over an in-memory listing whose rows carry no sealed name (so the keystore is never asked), and a ledger override
 * the test chooses. Building the real seam rather than a stub keeps the use cases tested against what Documents
 * actually answers, erased state included.
 *
 * @internal
 */
final class EvidenceDocumentsDouble
{
    /** @var array<string, DocumentListRow> */
    private array $rows = [];

    private bool $overrideActive = false;

    public int $findManyCalls = 0;

    public function withDocument(
        string $id,
        DocumentState $state = DocumentState::Ready,
        ?string $retentionReason = 'contract-execution',
        ?string $legalBasis = 'legitimate-interests',
        ?DateTimeImmutable $reviewAt = null,
    ): self {
        $this->rows[\strtolower($id)] = new DocumentListRow(
            $id,
            $id,
            null,
            $state,
            \str_repeat('a', 64),
            1024,
            $id,
            null,
            $retentionReason,
            $legalBasis,
            $reviewAt,
            new DateTimeImmutable('2030-01-02T03:04:05+00:00'),
        );

        return $this;
    }

    public function withOverrideActive(): self
    {
        $this->overrideActive = true;

        return $this;
    }

    public function seam(): DescribeEvidenceDocuments
    {
        $listing = new readonly class ($this) implements DocumentListingRepository {
            public function __construct(private EvidenceDocumentsDouble $double)
            {
            }

            #[Override]
            public function search(SearchCriteria $criteria): Page
            {
                throw new LogicException('The evidence seam never searches.');
            }

            #[Override]
            public function find(string $id): ?DocumentListRow
            {
                throw new LogicException('The evidence seam reads in batches.');
            }

            #[Override]
            public function findMany(array $ids): array
            {
                return $this->double->rowsFor($ids);
            }
        };

        $override = new readonly class ($this->overrideActive) implements ErasureLedgerOverride {
            public function __construct(private bool $active)
            {
            }

            #[Override]
            public function isActive(): bool
            {
                return $this->active;
            }
        };

        return new DescribeEvidenceDocuments(
            $listing,
            new DocumentNameOpener($this->encryptorNeverCalled()),
            $override,
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<DocumentListRow>
     */
    public function rowsFor(array $ids): array
    {
        ++$this->findManyCalls;

        $found = [];

        foreach (\array_unique(\array_map(\strtolower(...), $ids)) as $id) {
            if (isset($this->rows[$id])) {
                $found[] = $this->rows[$id];
            }
        }

        return $found;
    }

    private function encryptorNeverCalled(): EnvelopeEncryptor
    {
        return new class implements EnvelopeEncryptor {
            #[Override]
            public function encrypt(EncryptionScopeId $scope, string $plaintext, string $aad): string
            {
                throw new LogicException('Not used.');
            }

            #[Override]
            public function decrypt(EncryptionScopeId $scope, string $ciphertext, string $aad): string
            {
                throw new LogicException('Not used.');
            }

            #[Override]
            public function decryptMany(array $values): array
            {
                throw new LogicException('No row carries a sealed name, so no name is opened.');
            }

            #[Override]
            public function destroyScope(EncryptionScopeId $scope, DestroyReason $reason): bool
            {
                throw new LogicException('Not used.');
            }
        };
    }
}
