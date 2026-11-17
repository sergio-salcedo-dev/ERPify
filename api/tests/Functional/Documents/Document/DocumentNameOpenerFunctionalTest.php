<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\NameCause;
use Erpify\Documents\Document\Application\Listing\NameSource;
use Erpify\Documents\Document\Application\Listing\OpenedName;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Erpify\Shared\Crypto\Infrastructure\ScopeKeyring;
use Erpify\Shared\Crypto\Infrastructure\SodiumEnvelopeEncryptor;
use Erpify\Tests\Unit\Shared\Crypto\CountingKeystore;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The name opener against real Postgres and the real sealer — the registered reader of the crypto read boundary for the
 * listing. A page costs ONE keystore read however many documents it holds; `dek-destroyed` (the expected outcome after
 * an erasure) and `decryption-failed` (an integrity signal) are each one document's `null` and never a throw; an erased
 * row costs no lookup; an outage of the keystore still fails the page.
 *
 * @internal
 */
#[CoversClass(DocumentNameOpener::class)]
final class DocumentNameOpenerFunctionalTest extends KernelTestCase
{
    use PlantsNamedDocuments;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aPageWithADestroyedScopeAndATamperedNameOpensTheRestWithOneKeystoreRead(): void
    {
        $good = $this->plantNamedDocument('good.pdf');
        $destroyed = $this->plantNamedDocument('destroyed.pdf');
        $tampered = $this->plantNamedDocument('tampered.pdf');
        $alsoGood = $this->plantNamedDocument('also-good.pdf');
        $erased = $this->plantNamedDocument(null, DocumentState::Erased);
        $this->destroyScopeOf($destroyed['scope']);
        $this->tamperWithTheNameOf($tampered['id']);
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));

        $opened = $this->opener($keystore)->open($this->sourcesOf([$good, $destroyed, $tampered, $alsoGood, $erased]));

        $this->assertSame(1, $keystore->reads(), 'one keystore read for the whole page');
        $this->assertSame(1, $keystore->batchReads);
        $this->assertSame('good.pdf', $this->at($opened, $good['id'])->name);
        $this->assertSame('also-good.pdf', $this->at($opened, $alsoGood['id'])->name);
        $this->assertNull($this->at($opened, $destroyed['id'])->name);
        $this->assertSame(
            NameCause::KeyMissing,
            $this->at($opened, $destroyed['id'])->failure,
            'dek-destroyed is the expected outcome after an erasure: a null name, never a throw',
        );
        $this->assertNull($this->at($opened, $tampered['id'])->name);
        $this->assertSame(
            NameCause::DecryptionFailed,
            $this->at($opened, $tampered['id'])->failure,
            'decryption-failed is an integrity signal: a null name, never a throw',
        );
        $this->assertNull($this->at($opened, $erased['id'])->name);
        $this->assertNull($this->at($opened, $erased['id'])->failure, 'a row with nothing to open is not a failure');
    }

    #[Test]
    public function anErasedRowAloneCostsNoKeystoreLookupAtAll(): void
    {
        $erased = $this->plantNamedDocument(null, DocumentState::Erased);
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));

        $opened = $this->opener($keystore)->open($this->sourcesOf([$erased]));

        $this->assertSame(0, $keystore->reads());
        $this->assertNull($this->at($opened, $erased['id'])->name);
    }

    #[Test]
    public function aCorruptLiveKeyRowIsADecryptionFailureOfThatDocumentOnly(): void
    {
        $good = $this->plantNamedDocument('good.pdf');
        $corrupt = $this->plantNamedDocument('corrupt.pdf');
        $this->connection()->executeStatement(
            'UPDATE dek_keystore SET wrapped_dek = NULL WHERE encryption_scope_id = :scope',
            ['scope' => 'Document:' . $corrupt['scope']],
        );
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));

        $opened = $this->opener($keystore)->open($this->sourcesOf([$good, $corrupt]));

        $this->assertSame('good.pdf', $this->at($opened, $good['id'])->name);
        $this->assertNull($this->at($opened, $corrupt['id'])->name);
        $this->assertSame(
            NameCause::DecryptionFailed,
            $this->at($opened, $corrupt['id'])->failure,
            'decryption-failed: a live key row with no key material is an integrity signal, not a destroyed key',
        );
    }

    #[Test]
    public function aScopeThatCannotBeNamedIsUnreadableAndTheRestOfThePageStillOpens(): void
    {
        $good = $this->plantNamedDocument('good.pdf');
        $keystore = new CountingKeystore(new DbalKeystore($this->connection()));
        $sources = [
            ...$this->sourcesOf([$good]),
            new NameSource('11111111-1111-4111-8111-111111111111', 'a:b', 'ciphertext'),
        ];

        $opened = $this->opener($keystore)->open($sources);

        $this->assertSame('good.pdf', $this->at($opened, $good['id'])->name);
        $this->assertSame(NameCause::Unreadable, $this->at($opened, '11111111-1111-4111-8111-111111111111')->failure);
    }

    #[Test]
    public function anOutageOfTheKeystoreStillFailsThePage(): void
    {
        $good = $this->plantNamedDocument('good.pdf');
        $opener = new DocumentNameOpener(new SodiumEnvelopeEncryptor(new ScopeKeyring(
            new ThrowingKeystore(),
            $this->auditKek(),
        )));

        $this->expectException(RuntimeException::class);

        $opener->open($this->sourcesOf([$good]));
    }

    /**
     * @param list<array{id: string, scope: string}> $planted
     *
     * @return list<NameSource>
     */
    private function sourcesOf(array $planted): array
    {
        $sources = [];

        foreach ($planted as $document) {
            $ciphertext = $this->connection()->fetchOne(
                'SELECT filename_ciphertext FROM document WHERE id = CAST(:id AS UUID)',
                ['id' => $document['id']],
            );
            $sources[] = new NameSource(
                $document['id'],
                $document['scope'],
                \is_string($ciphertext) ? $ciphertext : null,
            );
        }

        return $sources;
    }

    /**
     * @param array<string, OpenedName> $opened
     */
    private function at(array $opened, string $documentId): OpenedName
    {
        $this->assertArrayHasKey($documentId, $opened);

        return $opened[$documentId];
    }

    private function opener(CountingKeystore $keystore): DocumentNameOpener
    {
        return new DocumentNameOpener(new SodiumEnvelopeEncryptor(new ScopeKeyring($keystore, $this->auditKek())));
    }
}
